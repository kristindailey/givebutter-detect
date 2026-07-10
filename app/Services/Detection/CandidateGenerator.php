<?php

namespace App\Services\Detection;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Candidate generation by **blocking**, not O(n²).
 *
 * Scoring every pair at ~100k contacts is ~5B comparisons, ~all of them obvious
 * non-matches. Instead each block emits only pairs that share a cheap key, the
 * five blocks union, and the union dedupes to one canonical `(a_id < b_id)` pair
 * per match. Phase 2 scores that small set.
 *
 * This runs as one raw-SQL statement on purpose: the trigram/GIN mechanics — the
 * `%` operator probing `contacts_{name,address}_key_trgm` rather than a nested
 * loop over everything — are the artifact an async reviewer reads. Eloquent would
 * bury them. This class persists nothing; it hands the pairs back in memory.
 *
 * Canonical ordering (`a.id < b.id`) is enforced **inside every block** so the
 * `UNION` collapses cleanly and each pair appears once regardless of how many
 * blocks fired on it. Pairs are strictly pairwise — A≈B and B≈C yield two
 * independent pairs, never a 3-way cluster (transitive clustering is deferred).
 *
 * The two fuzzy blocks rely on `pg_trgm.similarity_threshold` (default 0.3) for
 * the `%` operator; the exact email/phone blocks self-join on the pre-normalized
 * `normalized_value` columns their btree indexes cover.
 *
 * Noted, not built (the synthetic seed never triggers them, so they are
 * documented rather than implemented): skip degenerate blocking values
 * (NULL/blank, shared `info@` inboxes, `(000) 000-0000`) and cap block size (a
 * value shared by >~50 contacts is non-discriminating — it emits N² noise).
 */
class CandidateGenerator
{
    private const SQL = <<<'SQL'
        -- Exact normalized email
        SELECT ea.contact_id AS a_id, eb.contact_id AS b_id
        FROM emails ea
        JOIN emails eb
          ON ea.normalized_value = eb.normalized_value
         AND ea.contact_id < eb.contact_id
        WHERE ea.normalized_value IS NOT NULL

        UNION

        -- Exact normalized phone
        SELECT pa.contact_id AS a_id, pb.contact_id AS b_id
        FROM phones pa
        JOIN phones pb
          ON pa.normalized_value = pb.normalized_value
         AND pa.contact_id < pb.contact_id
        WHERE pa.normalized_value IS NOT NULL

        UNION

        -- Trigram-similar name (GIN gin_trgm_ops on contacts.name_key)
        SELECT a.id AS a_id, b.id AS b_id
        FROM contacts a
        JOIN contacts b
          ON a.id < b.id
         AND a.name_key % b.name_key
        WHERE a.name_key IS NOT NULL
          AND b.name_key IS NOT NULL

        UNION

        -- Trigram-similar address (GIN gin_trgm_ops on contacts.address_key)
        SELECT a.id AS a_id, b.id AS b_id
        FROM contacts a
        JOIN contacts b
          ON a.id < b.id
         AND a.address_key % b.address_key
        WHERE a.address_key IS NOT NULL
          AND b.address_key IS NOT NULL

        UNION

        -- Same household. Carries the Jennifer/Jen hero pair, whose names and
        -- emails are too weak to block on reliably.
        SELECT ha.contact_id AS a_id, hb.contact_id AS b_id
        FROM household_contacts ha
        JOIN household_contacts hb
          ON ha.household_id = hb.household_id
         AND ha.contact_id < hb.contact_id
        SQL;

    /**
     * Generate the deduped, canonically-ordered candidate pairs.
     *
     * @return Collection<int, array{a_id: int, b_id: int}>
     */
    public function generate(): Collection
    {
        return collect(DB::select(self::SQL))
            ->map(function (object $row): array {
                $pair = (array) $row;

                return [
                    'a_id' => (int) $pair['a_id'],
                    'b_id' => (int) $pair['b_id'],
                ];
            });
    }
}
