<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Transaction;
use App\Services\Detection\Normalizer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The trust-critical core. One `project()` method does both the dry-run preview
 * and the real commit (`commit=false` vs `commit=true`), so **what the user
 * previews is exactly what commits** — the before/after panel and the committing
 * POST call the same code with the same projection shape.
 *
 * Field resolution is three-tier:
 *   1. Scalars   — surfaced where the two values differ. A genuine conflict (both
 *                  present) is a per-field picker; a gap (survivor empty, loser
 *                  has a value) auto-fills from the loser so a merge never drops
 *                  information — read-only, not a decision.
 *   2. Arrays    — auto-union with dedupe, read-only "kept both".
 *   3. Derived   — recomputed from the **post-repoint union** of both contacts'
 *                  transactions, excluding refunded / non-succeeded rows.
 *
 * On commit, inside one DB transaction: loser transactions re-point to the
 * survivor, unique array rows move over, scalars apply per the picker / gap-fill,
 * the three derived fields recompute, and the loser is soft-deleted **last** — so
 * its relations stay readable while they are being moved.
 */
class MergeService
{
    /**
     * Scalar identity fields the picker / gap-fill can resolve and the survivor
     * proposal counts for completeness. Derived money fields and internal blocking
     * keys are deliberately excluded — they are recomputed, not picked. Picks
     * arriving from user input are whitelisted against this list before any write.
     *
     * @var list<string>
     */
    private const array SCALAR_FIELDS = [
        'prefix', 'first_name', 'preferred_name', 'middle_name', 'last_name',
        'suffix', 'dob', 'company', 'title', 'primary_email', 'primary_phone',
        'external_id',
    ];

    public function __construct(private Normalizer $normalizer) {}

    /**
     * Auto-propose the survivor: the **more complete** record, tie-broken by
     * recency, then giving. The richer record is kept (Jennifer wins case 1). The
     * caller may override — donor tenure is *not* a survivor concern, since
     * `contact_since` recomputes over the union regardless of who survives.
     */
    public function proposeSurvivor(Contact $a, Contact $b): Contact
    {
        $completenessA = $this->completeness($a);
        $completenessB = $this->completeness($b);
        if ($completenessA !== $completenessB) {
            return $completenessA > $completenessB ? $a : $b;
        }

        if ($a->updated_at != $b->updated_at) {
            return $a->updated_at?->gt($b->updated_at) ? $a : $b;
        }

        $givingA = (float) $a->total_contributions;
        $givingB = (float) $b->total_contributions;
        if ($givingA !== $givingB) {
            return $givingA > $givingB ? $a : $b;
        }

        return $a;
    }

    /**
     * Project the merge of `$loser` into `$survivor`. With `commit=false` this is a
     * pure dry run that writes nothing; with `commit=true` it performs the merge in
     * one DB transaction. Both return the identical projection shape, so the
     * preview equals the commit.
     *
     * @param  array<string, string>  $picks  field => 'survivor'|'loser'; absent = survivor
     * @return array{
     *     survivor_id: int,
     *     loser_id: int,
     *     scalars: array<string, array{survivor: ?string, loser: ?string, chosen: ?string, conflict: bool}>,
     *     arrays: array<string, list<array<string, mixed>>>,
     *     derived: array<string, array{before: ?string, after: ?string}>
     * }
     */
    public function project(Contact $survivor, Contact $loser, array $picks = [], bool $commit = false): array
    {
        $survivor->loadMissing(['emails', 'phones', 'addresses', 'externalIds', 'tags']);
        $loser->loadMissing(['emails', 'phones', 'addresses', 'externalIds', 'tags']);

        $scalars = $this->projectScalars($survivor, $loser, $picks);
        $arrays = $this->projectArrays($survivor, $loser);
        $after = $this->recomputeDerived([$survivor->id, $loser->id]);
        $derived = $this->projectDerived($survivor, $after);

        if ($commit) {
            $this->commit($survivor, $loser, $picks, $after);
        }

        return [
            'survivor_id' => $survivor->id,
            'loser_id' => $loser->id,
            'scalars' => $scalars,
            'arrays' => $arrays,
            'derived' => $derived,
        ];
    }

    /**
     * Tier 1 — scalars, projected for display. Each resolved field carries the two
     * sides, the `chosen` value, and whether it's a `conflict` (both present, a
     * picker) or a gap-fill (`conflict: false`, auto-taken from the loser).
     *
     * @param  array<string, string>  $picks
     * @return array<string, array{survivor: ?string, loser: ?string, chosen: ?string, conflict: bool}>
     */
    private function projectScalars(Contact $survivor, Contact $loser, array $picks): array
    {
        $scalars = [];

        foreach ($this->resolveScalars($survivor, $loser, $picks) as $field => $resolution) {
            $scalars[$field] = [
                'survivor' => $resolution['survivor'],
                'loser' => $resolution['loser'],
                'chosen' => $resolution['source'] === 'loser' ? $resolution['loser'] : $resolution['survivor'],
                'conflict' => $resolution['conflict'],
            ];
        }

        return $scalars;
    }

    /**
     * The single source of truth for scalar resolution — both the projection and
     * the commit read it, so what the user previews is what commits. A field is
     * resolved only when the loser can change the survivor:
     *
     * - **conflict** (both present, different): a decision → `source` follows the
     *   picker, defaulting to the survivor.
     * - **gap-fill** (survivor empty, loser present): no decision → `source` is the
     *   loser, so a merge never silently drops the loser's value.
     *
     * Identical values, and fields where only the survivor has a value, add
     * nothing and are skipped. Iterating the whitelist means user `picks` can only
     * ever move a known scalar field.
     *
     * @param  array<string, string>  $picks
     * @return array<string, array{survivor: ?string, loser: ?string, source: string, conflict: bool}>
     */
    private function resolveScalars(Contact $survivor, Contact $loser, array $picks): array
    {
        $resolved = [];

        foreach (self::SCALAR_FIELDS as $field) {
            $survivorValue = $this->displayScalar($survivor, $field);
            $loserValue = $this->displayScalar($loser, $field);

            if ($loserValue === null || $survivorValue === $loserValue) {
                continue;
            }

            if ($survivorValue === null) {
                $resolved[$field] = ['survivor' => null, 'loser' => $loserValue, 'source' => 'loser', 'conflict' => false];

                continue;
            }

            $source = ($picks[$field] ?? null) === 'loser' ? 'loser' : 'survivor';
            $resolved[$field] = ['survivor' => $survivorValue, 'loser' => $loserValue, 'source' => $source, 'conflict' => true];
        }

        return $resolved;
    }

    /**
     * Tier 2 — arrays. Each array auto-unions with dedupe on its identity key, so
     * the preview renders a read-only "kept both" summary.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function projectArrays(Contact $survivor, Contact $loser): array
    {
        return [
            'emails' => $this->unionRelation($survivor, $loser, 'emails', $this->emailKey(...), ['type', 'value']),
            'phones' => $this->unionRelation($survivor, $loser, 'phones', $this->phoneKey(...), ['type', 'value']),
            'addresses' => $this->unionRelation($survivor, $loser, 'addresses', $this->addressKey(...), [
                'address_1', 'address_2', 'city', 'state', 'zipcode', 'country', 'type', 'is_primary',
            ]),
            'tags' => $this->unionRelation($survivor, $loser, 'tags', fn (Model $tag): ?string => $this->stringAttr($tag, 'name'), ['name']),
            'external_ids' => $this->unionRelation($survivor, $loser, 'externalIds', $this->externalIdKey(...), ['label', 'external_id']),
        ];
    }

    /**
     * Tier 3 — derived. `before` is the survivor's current stored value; `after` is
     * the recompute over the union. The `contact_since` correction backward is the
     * demo payoff.
     *
     * @param  array{total_contributions: string, contact_since: ?string, last_donation_amount: ?string}  $after
     * @return array<string, array{before: ?string, after: ?string}>
     */
    private function projectDerived(Contact $survivor, array $after): array
    {
        return [
            'total_contributions' => [
                'before' => (string) $survivor->total_contributions,
                'after' => $after['total_contributions'],
            ],
            'contact_since' => [
                'before' => $survivor->contact_since?->toDateString(),
                'after' => $after['contact_since'],
            ],
            'last_donation_amount' => [
                'before' => $survivor->last_donation_amount,
                'after' => $after['last_donation_amount'],
            ],
        ];
    }

    /**
     * The commit — one DB transaction. The loser is archived **last**: transactions
     * and array rows must move while the loser is still readable, and only then is
     * it soft-deleted.
     *
     * @param  array<string, string>  $picks
     * @param  array{total_contributions: string, contact_since: ?string, last_donation_amount: ?string}  $after
     */
    private function commit(Contact $survivor, Contact $loser, array $picks, array $after): void
    {
        DB::transaction(function () use ($survivor, $loser, $picks, $after): void {
            Transaction::query()
                ->where('contact_id', $loser->id)
                ->update(['contact_id' => $survivor->id]);

            $this->moveRelationRows($survivor, $loser, 'emails', $this->emailKey(...));
            $this->moveRelationRows($survivor, $loser, 'phones', $this->phoneKey(...));
            $this->moveRelationRows($survivor, $loser, 'addresses', $this->addressKey(...));
            $this->moveRelationRows($survivor, $loser, 'externalIds', $this->externalIdKey(...));
            $survivor->tags()->syncWithoutDetaching($loser->tags->pluck('id')->all());

            foreach ($this->resolveScalars($survivor, $loser, $picks) as $field => $resolution) {
                if ($resolution['source'] === 'loser') {
                    $survivor->setAttribute($field, $loser->getAttribute($field));
                }
            }

            // A name pick can change the blocking key; keep it consistent for the
            // next detect:run. The primary address is untouched, so `address_key`
            // is left as is.
            $survivor->name_key = $this->normalizer->nameKey($survivor->first_name, $survivor->last_name);

            // Derived fields are guarded out of `$fillable`, so force them.
            $survivor->forceFill($after)->save();

            $loser->archive();
        });
    }

    /**
     * Union a `hasMany`/`belongsToMany` relation across both contacts, deduped on
     * `$keyFn`, projecting only the display columns. Survivor items come first.
     *
     * @param  callable(Model): ?string  $keyFn
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    private function unionRelation(Contact $survivor, Contact $loser, string $relation, callable $keyFn, array $columns): array
    {
        $union = [];
        $seen = [];

        foreach ([$survivor, $loser] as $contact) {
            foreach ($contact->{$relation} as $item) {
                $key = $keyFn($item);

                if ($key === null || in_array($key, $seen, true)) {
                    continue;
                }

                $seen[] = $key;
                $union[] = $item->only($columns);
            }
        }

        return $union;
    }

    /**
     * Re-point the loser's unique rows in a `hasMany` relation to the survivor.
     * Rows whose dedupe key the survivor already holds stay on the (now archived)
     * loser — the union keeps one copy, not two.
     *
     * @param  callable(Model): ?string  $keyFn
     */
    private function moveRelationRows(Contact $survivor, Contact $loser, string $relation, callable $keyFn): void
    {
        $survivorKeys = $survivor->{$relation}
            ->map($keyFn)
            ->filter()
            ->values()
            ->all();

        foreach ($loser->{$relation} as $item) {
            $key = $keyFn($item);

            if ($key === null || in_array($key, $survivorKeys, true)) {
                continue;
            }

            $item->contact_id = $survivor->id;
            $item->save();
            $survivorKeys[] = $key;
        }
    }

    /**
     * Recompute the three derived fields over the union, excluding refunded /
     * non-succeeded rows. Mirrors the seeder's rules exactly (the seeder duplicates
     * them so the money-math fixture doesn't depend on the code it verifies).
     *
     * @param  list<int>  $contactIds
     * @return array{total_contributions: string, contact_since: ?string, last_donation_amount: ?string}
     */
    private function recomputeDerived(array $contactIds): array
    {
        $succeeded = Transaction::query()
            ->whereIn('contact_id', $contactIds)
            ->where('status', Transaction::STATUS_SUCCEEDED)
            ->whereNull('refunded_at')
            ->orderBy('captured_at')
            ->get();

        if ($succeeded->isEmpty()) {
            return ['total_contributions' => '0.00', 'contact_since' => null, 'last_donation_amount' => null];
        }

        return [
            'total_contributions' => $this->money($succeeded->sum(fn (Transaction $transaction): float => (float) $transaction->amount)),
            'contact_since' => $succeeded->first()->captured_at?->toDateString(),
            'last_donation_amount' => $this->money((float) $succeeded->last()->amount),
        ];
    }

    /**
     * The count of non-null scalar identity fields — the survivor proposal's
     * "more complete" measure.
     */
    private function completeness(Contact $contact): int
    {
        $count = 0;

        foreach (self::SCALAR_FIELDS as $field) {
            if ($this->displayScalar($contact, $field) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * A scalar field's comparable/displayable string, or null when empty. Dates
     * fold to `Y-m-d` so a `dob` conflict compares cleanly.
     */
    private function displayScalar(Contact $contact, string $field): ?string
    {
        $value = $contact->getAttribute($field);

        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    private function emailKey(Model $email): ?string
    {
        return $this->stringAttr($email, 'normalized_value')
            ?? $this->normalizer->email($this->stringAttr($email, 'value'));
    }

    private function phoneKey(Model $phone): ?string
    {
        return $this->stringAttr($phone, 'normalized_value')
            ?? $this->normalizer->phone($this->stringAttr($phone, 'value'));
    }

    private function addressKey(Model $address): ?string
    {
        return $this->normalizer->addressKey(
            $this->stringAttr($address, 'address_1'),
            $this->stringAttr($address, 'city'),
            $this->stringAttr($address, 'zipcode'),
        );
    }

    private function externalIdKey(Model $externalId): string
    {
        return ($this->stringAttr($externalId, 'label') ?? '').'|'.($this->stringAttr($externalId, 'external_id') ?? '');
    }

    /**
     * A model attribute coerced to a non-empty string, or null. Lets the array key
     * functions read columns off the generic `Model` the union helpers hand them.
     */
    private function stringAttr(Model $model, string $key): ?string
    {
        $value = $model->getAttribute($key);

        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
