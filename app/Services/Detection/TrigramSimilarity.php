<?php

namespace App\Services\Detection;

use Illuminate\Support\Facades\DB;

/**
 * `pg_trgm` similarity, resolved in batches.
 *
 * Postgres stays the source of truth — the trigram algorithm is not reimplemented
 * in PHP, because matching its exact padding and tokenizing rules is a correctness
 * risk the hero scores would pay for. What changes is how *often* we ask.
 *
 * Scoring a pair needs a similarity for the name keys and one for the address keys,
 * and a query apiece is sub-millisecond against localhost but a network round trip
 * against managed Postgres. At ~4k candidate pairs that was ~8k sequential round
 * trips: ~1s in development, 22s+ on the deployed demo, nearly all of it the app
 * blocked on the network rather than Postgres computing anything. `preload()` resolves
 * the whole set in one query per chunk instead, and `score()` reads the answers back
 * out of memory.
 *
 * A miss still falls back to a single query, so callers that never preload (anything
 * outside `detect:run`) keep working exactly as before.
 */
class TrigramSimilarity
{
    /**
     * Chunk size for the batched lookup. Each pair binds two parameters, and Postgres
     * caps a statement at 65535 of them, so this leaves an order of magnitude of room.
     */
    private const int CHUNK = 1000;

    /** @var array<string, float> */
    private array $cache = [];

    /**
     * Resolve every pair up front, one query per chunk.
     *
     * Pairs that short out in `score()` (null, empty, identical) are skipped rather
     * than sent to Postgres, and the rest are deduped — the same two name keys often
     * recur across candidate pairs.
     *
     * @param  iterable<array{0: ?string, 1: ?string}>  $pairs
     */
    public function preload(iterable $pairs): void
    {
        $pending = [];

        foreach ($pairs as [$a, $b]) {
            if ($this->shortCircuit($a, $b) !== null) {
                continue;
            }

            $key = $this->key($a, $b);

            if (! isset($this->cache[$key]) && ! isset($pending[$key])) {
                $pending[$key] = [$a, $b];
            }
        }

        foreach (array_chunk($pending, self::CHUNK) as $chunk) {
            $this->resolveChunk($chunk);
        }
    }

    /**
     * Raw `pg_trgm` similarity for two keys. The caller applies any floor: this is the
     * measurement, not the scoring policy.
     */
    public function score(?string $a, ?string $b): float
    {
        $shortCircuit = $this->shortCircuit($a, $b);

        if ($shortCircuit !== null) {
            return $shortCircuit;
        }

        $key = $this->key($a, $b);

        if (! isset($this->cache[$key])) {
            $this->resolveChunk([[$a, $b]]);
        }

        // The lookup always returns a row, so the fallback is unreachable — it is here
        // because a scorer that throws on a missing similarity would fail a merge
        // preview, and returning "no agreement" is the safe direction to be wrong in.
        return $this->cache[$key] ?? 0.0;
    }

    /**
     * One query for many pairs: a `VALUES` list joined to `similarity()`. The `::text`
     * casts are required — Postgres cannot infer a placeholder's type inside `VALUES`.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    private function resolveChunk(array $pairs): void
    {
        $bindings = [];

        foreach ($pairs as [$a, $b]) {
            $bindings[] = $a;
            $bindings[] = $b;
        }

        $values = implode(', ', array_fill(0, count($pairs), '(?::text, ?::text)'));

        $rows = DB::select(
            "SELECT l, r, similarity(l, r) AS score FROM (VALUES {$values}) AS t (l, r)",
            $bindings,
        );

        foreach ($rows as $row) {
            $row = (array) $row;

            $this->cache[$this->key((string) $row['l'], (string) $row['r'])] = (float) $row['score'];
        }
    }

    /**
     * The answers Postgres never needs to be asked for: a missing key means the signal
     * cannot fire, and identical keys are 1.0 by definition.
     */
    private function shortCircuit(?string $a, ?string $b): ?float
    {
        if ($a === null || $b === null || $a === '' || $b === '') {
            return 0.0;
        }

        return $a === $b ? 1.0 : null;
    }

    /**
     * `similarity()` is symmetric, so the pair is sorted into a canonical key — the
     * cache then hits regardless of which side of the candidate pair a key arrived on.
     */
    private function key(string $a, string $b): string
    {
        return $a < $b ? $a."\0".$b : $b."\0".$a;
    }
}
