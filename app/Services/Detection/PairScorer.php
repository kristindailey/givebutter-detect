<?php

namespace App\Services\Detection;

use App\Models\Contact;

/**
 * Weighted per-signal scorer with the asymmetric household modifier — the pitch
 * centerpiece. Produces a 0–100 confidence per candidate pair plus a
 * `signal_breakdown` explaining *why*, so the queue can render chips and the
 * merge API can echo the reasoning without recomputing.
 *
 * `score()` is a **pure read**: it derives agreement from the two contacts (and
 * a read-only `similarity()` probe for trigram signals) and returns a result —
 * it writes nothing. Persistence and the ≥60 band filter live in `detect:run`,
 * so the parent/child pair scores ~35 here and is *verifiable* even though it
 * never reaches the queue.
 *
 * The four additive signals contribute `weight × agreement`. Household is not
 * additive: it dampens a shared-inbox email, boosts a same-household/same-`dob`/
 * same-name pair, or — on a `dob` conflict — pushes toward "different people".
 * That single asymmetry is what wins both hero cases where a naive exact-match
 * rule fails one in each direction.
 */
class PairScorer
{
    /** @var array{email: float, phone: float, name: float, address: float} */
    private array $weights;

    /** @var array{auto: float, review: float} */
    private array $bands;

    private float $dampenFactor;

    private float $boost;

    private float $conflictPenalty;

    private float $strongNameThreshold;

    private float $similarityFloor;

    /** @var array<string, list<string>> */
    private array $nicknames;

    public function __construct(private Normalizer $normalizer, private TrigramSimilarity $trigram)
    {
        $this->weights = [
            'email' => (float) config('detection.weights.email'),
            'phone' => (float) config('detection.weights.phone'),
            'name' => (float) config('detection.weights.name'),
            'address' => (float) config('detection.weights.address'),
        ];
        $this->bands = [
            'auto' => (float) config('detection.bands.auto'),
            'review' => (float) config('detection.bands.review'),
        ];
        $this->dampenFactor = (float) config('detection.modifier.email_dampen_factor');
        $this->boost = (float) config('detection.modifier.boost');
        $this->conflictPenalty = (float) config('detection.modifier.conflict_penalty');
        $this->strongNameThreshold = (float) config('detection.modifier.strong_name_threshold');
        $this->similarityFloor = (float) config('detection.similarity_floor');

        /** @var array<string, list<string>> $nicknames */
        $nicknames = config('nicknames');
        $this->nicknames = $nicknames;
    }

    /**
     * Resolve the trigram similarities a batch of pairs will need, in one query per
     * chunk, before any of them are scored.
     *
     * Optional: `score()` is correct without it and simply asks per pair. `detect:run`
     * calls it because per-pair is thousands of network round trips against a remote
     * Postgres — see `TrigramSimilarity`.
     *
     * @param  iterable<array{0: ?string, 1: ?string}>  $pairs
     */
    public function preloadSimilarities(iterable $pairs): void
    {
        $this->trigram->preload($pairs);
    }

    /**
     * Score one candidate pair.
     *
     * @return array{score: float, band: string, breakdown: list<array<string, mixed>>}
     */
    public function score(Contact $a, Contact $b): array
    {
        $sharedEmails = $this->sharedValues($a, $b, 'emails');
        $sharedPhones = $this->sharedValues($a, $b, 'phones');
        [$nameAgreement, $nameVia] = $this->nameAgreement($a, $b);
        $addressAgreement = $this->similarity($a->address_key, $b->address_key);

        $sharedHousehold = $this->shareHousehold($a, $b);
        $dampen = $sharedHousehold && $sharedEmails !== [];
        $boost = $sharedHousehold && $nameAgreement >= $this->strongNameThreshold && $this->dobAgree($a, $b);
        $conflict = $sharedHousehold && $this->dobConflict($a, $b);

        $emailContribution = ($sharedEmails === [] ? 0.0 : $this->weights['email'])
            * ($dampen ? $this->dampenFactor : 1.0);
        $phoneContribution = $sharedPhones === [] ? 0.0 : $this->weights['phone'];
        $nameContribution = $this->weights['name'] * $nameAgreement;
        $addressContribution = $this->weights['address'] * $addressAgreement;

        $score = $emailContribution + $phoneContribution + $nameContribution + $addressContribution
            + ($boost ? $this->boost : 0.0)
            - ($conflict ? $this->conflictPenalty : 0.0);
        $score = round(max(0.0, min(100.0, $score)), 2);

        return [
            'score' => $score,
            'band' => $this->band($score),
            'breakdown' => $this->breakdown(
                $a,
                $b,
                emailContribution: $emailContribution,
                sharedEmails: $sharedEmails,
                dampen: $dampen,
                phoneContribution: $phoneContribution,
                sharedPhones: $sharedPhones,
                nameContribution: $nameContribution,
                nameVia: $nameVia,
                addressContribution: $addressContribution,
                addressAgreement: $addressAgreement,
                sharedHousehold: $sharedHousehold,
                boost: $boost,
                conflict: $conflict,
            ),
        ];
    }

    public function band(float $score): string
    {
        return match (true) {
            $score >= $this->bands['auto'] => 'auto',
            $score >= $this->bands['review'] => 'review',
            default => 'ignore',
        };
    }

    /**
     * Name agreement is the **max** of exact key match, nickname-expanded match,
     * and trigram similarity — never their sum, so an exact match and a high
     * trigram are not double-counted. Returns the agreement and the method that
     * produced it, for the breakdown.
     *
     * @return array{0: float, 1: string}
     */
    private function nameAgreement(Contact $a, Contact $b): array
    {
        if ($a->name_key !== null && $a->name_key === $b->name_key) {
            return [1.0, 'exact'];
        }

        if ($this->nicknameMatch($a, $b)) {
            return [1.0, 'nickname'];
        }

        return [$this->similarity($a->name_key, $b->name_key), 'trigram'];
    }

    /**
     * Preferred-name-aware nickname match: last names must agree exactly, and some
     * pairing of the two sides' first/preferred names must be linked in the
     * diminutive table (`Jen` ≈ `Jennifer`). This is what makes the hero pair
     * agree on name where a raw trigram (~0.56) would be only borderline.
     */
    private function nicknameMatch(Contact $a, Contact $b): bool
    {
        $lastA = $this->normalizer->name($a->last_name);
        $lastB = $this->normalizer->name($b->last_name);

        if ($lastA === '' || $lastA !== $lastB) {
            return false;
        }

        foreach ($this->firstNameVariants($a) as $first) {
            foreach ($this->firstNameVariants($b) as $other) {
                if ($first === $other || $this->nicknamesLinked($first, $other)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A contact's first-name candidates, normalized: their `first_name` and
     * `preferred_name` both count, so `Jen` (preferred) still matches `Jennifer`.
     *
     * @return list<string>
     */
    private function firstNameVariants(Contact $contact): array
    {
        $variants = [];

        foreach ([$contact->first_name, $contact->preferred_name] as $raw) {
            $normalized = $this->normalizer->name($raw);

            if ($normalized !== '' && ! in_array($normalized, $variants, true)) {
                $variants[] = $normalized;
            }
        }

        return $variants;
    }

    /**
     * Bidirectional lookup in the diminutive table: formal→alias, alias→formal, or
     * two aliases of the same formal name.
     */
    private function nicknamesLinked(string $a, string $b): bool
    {
        if (in_array($b, $this->nicknames[$a] ?? [], true)) {
            return true;
        }

        if (in_array($a, $this->nicknames[$b] ?? [], true)) {
            return true;
        }

        foreach ($this->nicknames as $aliases) {
            if (in_array($a, $aliases, true) && in_array($b, $aliases, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The normalized values shared across a repeated relation (`emails` /
     * `phones`) — any-to-any across the arrays, not just the primary.
     *
     * @return list<string>
     */
    private function sharedValues(Contact $a, Contact $b, string $relation): array
    {
        $valuesA = $a->{$relation}->pluck('normalized_value')->filter();
        $valuesB = $b->{$relation}->pluck('normalized_value')->filter();

        return $valuesA->intersect($valuesB)->unique()->values()->all();
    }

    private function shareHousehold(Contact $a, Contact $b): bool
    {
        return $a->households->pluck('id')
            ->intersect($b->households->pluck('id'))
            ->isNotEmpty();
    }

    private function dobAgree(Contact $a, Contact $b): bool
    {
        return $a->dob !== null && $b->dob !== null
            && $a->dob->toDateString() === $b->dob->toDateString();
    }

    private function dobConflict(Contact $a, Contact $b): bool
    {
        return $a->dob !== null && $b->dob !== null
            && $a->dob->toDateString() !== $b->dob->toDateString();
    }

    /**
     * Trigram similarity via Postgres' `pg_trgm`, floored at the configured threshold
     * so sub-threshold noise contributes nothing.
     *
     * The lookup itself belongs to `TrigramSimilarity`, which serves it from a batch
     * `detect:run` preloads — a query per pair is free against localhost and a network
     * round trip against managed Postgres. The floor stays here: it is scoring policy,
     * not measurement.
     */
    private function similarity(?string $a, ?string $b): float
    {
        $score = $this->trigram->score($a, $b);

        return $score >= $this->similarityFloor ? $score : 0.0;
    }

    /**
     * Assemble the `signal_breakdown` array. Additive signals carry `signal`,
     * `contribution`, and the `matched` values; the dampened email carries a
     * `note`; the household entry carries the `modifier` nudge (`+boost` /
     * `-conflict` / `-dampen`) and its `reason`.
     *
     * @param  list<string>  $sharedEmails
     * @param  list<string>  $sharedPhones
     * @return list<array<string, mixed>>
     */
    private function breakdown(
        Contact $a,
        Contact $b,
        float $emailContribution,
        array $sharedEmails,
        bool $dampen,
        float $phoneContribution,
        array $sharedPhones,
        float $nameContribution,
        string $nameVia,
        float $addressContribution,
        float $addressAgreement,
        bool $sharedHousehold,
        bool $boost,
        bool $conflict,
    ): array {
        $name = [
            'signal' => 'name',
            'contribution' => round($nameContribution, 2),
            'matched' => [$this->displayName($a), $this->displayName($b)],
            'via' => $nameVia,
        ];

        $email = [
            'signal' => 'email',
            'contribution' => round($emailContribution, 2),
            'matched' => $sharedEmails,
        ];
        if ($dampen) {
            $email['note'] = 'dampened: shared household';
        }

        $phone = [
            'signal' => 'phone',
            'contribution' => round($phoneContribution, 2),
            'matched' => $sharedPhones,
        ];

        $address = [
            'signal' => 'address',
            'contribution' => round($addressContribution, 2),
            'matched' => $addressAgreement > 0 && $a->address_key !== null ? [$a->address_key] : [],
        ];

        $breakdown = [$name, $email, $phone, $address];

        if ($sharedHousehold) {
            $breakdown[] = [
                'signal' => 'household',
                ...match (true) {
                    $boost => ['modifier' => '+boost', 'reason' => 'dob agreement'],
                    $conflict => ['modifier' => '-conflict', 'reason' => 'dob conflict'],
                    $dampen => ['modifier' => '-dampen', 'reason' => 'shared inbox'],
                    default => ['modifier' => 'neutral', 'reason' => 'co-membership'],
                },
            ];
        }

        return $breakdown;
    }

    private function displayName(Contact $contact): string
    {
        return trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));
    }
}
