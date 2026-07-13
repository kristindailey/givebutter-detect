<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\DuplicateCandidate;
use App\Services\Detection\CandidateGenerator;
use App\Services\Detection\PairScorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The batch scoring job. Generates candidate pairs (Phase 1's blocking), scores
 * each with the weighted `PairScorer` + household modifier, and refreshes the
 * `duplicate_candidates` queue.
 *
 * A rerun is idempotent: scores are upserted in place and pending pairs that no
 * longer fire are pruned, but pairs already merged or dismissed keep that
 * resolution. So `detect:run` means "rescore the current data without undoing
 * decisions" — resetting the demo to zero is `seed:demo --detect`, a different verb.
 *
 * Only pairs at or above the Review band are persisted; Ignore-band pairs (the
 * parent/child ~35 among them) are scored and discarded, which is why the review
 * queue never has to filter noise on read. Candidates are precomputed here rather
 * than scored on page load — the honest production shape, and far faster.
 */
#[Signature('detect:run')]
#[Description('Score candidate pairs and repopulate the duplicate_candidates queue')]
class DetectRun extends Command
{
    public function handle(CandidateGenerator $generator, PairScorer $scorer): int
    {
        $pairs = $generator->generate();
        $contacts = $this->loadContacts($pairs);
        $scorer->preloadSimilarities($this->similarityPairs($pairs, $contacts));
        $reviewFloor = (float) config('detection.bands.review');
        $now = now();

        $rows = [];

        foreach ($pairs as $pair) {
            $a = $contacts->get($pair['a_id']);
            $b = $contacts->get($pair['b_id']);

            if ($a === null || $b === null) {
                continue;
            }

            $result = $scorer->score($a, $b);

            if ($result['score'] < $reviewFloor) {
                continue;
            }

            $rows[] = [
                'contact_a_id' => $pair['a_id'],
                'contact_b_id' => $pair['b_id'],
                'score' => $result['score'],
                'signal_breakdown' => json_encode($result['breakdown'], JSON_THROW_ON_ERROR),
                'detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $pruned = DB::transaction(function () use ($rows): int {
            $pruned = $this->prunePendingPairsNoLongerDetected($rows);

            foreach (array_chunk($rows, 500) as $chunk) {
                // `resolution`, `resolved_at`, and `detected_at` are deliberately not
                // in the update list: a rerun refreshes the score, never a decision
                // already made or the moment the pair was first seen.
                DuplicateCandidate::upsert(
                    $chunk,
                    ['contact_a_id', 'contact_b_id'],
                    ['score', 'signal_breakdown', 'updated_at'],
                );
            }

            return $pruned;
        });

        $this->components->info(sprintf(
            'Scored %d candidate pairs; %d at or above the queue floor (score ≥ %d); %d stale pending pruned.',
            $pairs->count(),
            count($rows),
            (int) $reviewFloor,
            $pruned,
        ));

        return self::SUCCESS;
    }

    /**
     * Drop pending rows this run no longer produces — the pair's data changed, or its
     * score fell below the band. Resolved rows survive: a merge and a dismissal are
     * both labeled history (the training signal for the weights, per the spec), and a
     * merged pair in particular must never come back to the queue.
     *
     * @param  list<array{contact_a_id: int, contact_b_id: int, ...}>  $rows
     */
    private function prunePendingPairsNoLongerDetected(array $rows): int
    {
        $detected = [];

        foreach ($rows as $row) {
            $detected[$row['contact_a_id'].':'.$row['contact_b_id']] = true;
        }

        $staleIds = DuplicateCandidate::query()
            ->where('resolution', DuplicateCandidate::RESOLUTION_PENDING)
            ->get(['id', 'contact_a_id', 'contact_b_id'])
            ->reject(fn (DuplicateCandidate $candidate): bool => isset($detected[$candidate->contact_a_id.':'.$candidate->contact_b_id]))
            ->pluck('id');

        if ($staleIds->isEmpty()) {
            return 0;
        }

        return DuplicateCandidate::query()->whereIn('id', $staleIds)->delete();
    }

    /**
     * The name and address key pairs the scorer will ask about, so they can be resolved
     * in one batch instead of a round trip apiece.
     *
     * Both keys are offered for every pair, including the name keys of pairs whose name
     * agreement will come from an exact or nickname match and never reach the trigram.
     * Predicting that here would mean duplicating the scorer's precedence rules in the
     * command — a correctness risk to save Postgres a few thousand trigram comparisons
     * it does in milliseconds inside a query it is already running.
     *
     * @param  Collection<int, array{a_id: int, b_id: int}>  $pairs
     * @param  Collection<int, Contact>  $contacts
     * @return list<array{0: ?string, 1: ?string}>
     */
    private function similarityPairs(Collection $pairs, Collection $contacts): array
    {
        $keys = [];

        foreach ($pairs as $pair) {
            $a = $contacts->get($pair['a_id']);
            $b = $contacts->get($pair['b_id']);

            if ($a === null || $b === null) {
                continue;
            }

            $keys[] = [$a->name_key, $b->name_key];
            $keys[] = [$a->address_key, $b->address_key];
        }

        return $keys;
    }

    /**
     * Load every contact referenced by a candidate pair, with the relations the
     * scorer reads eager-loaded so scoring adds no per-pair queries beyond the
     * trigram probe.
     *
     * The default (non-archived) scope is right here: the generator already excludes
     * archived contacts, so a merge loser can never reach the scorer.
     *
     * @param  Collection<int, array{a_id: int, b_id: int}>  $pairs
     * @return Collection<int, Contact>
     */
    private function loadContacts(Collection $pairs): Collection
    {
        $ids = $pairs
            ->flatMap(fn (array $pair): array => [$pair['a_id'], $pair['b_id']])
            ->unique();

        return Contact::query()
            ->with(['emails', 'phones', 'households'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }
}
