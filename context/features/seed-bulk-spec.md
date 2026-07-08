# Seed Bulk — 100k Synthetic (Stretch)

## Overview

**Stretch — build only after the core demo and repo are done and polished.** `seed:bulk` generates 100k synthetic contacts so `EXPLAIN ANALYZE` can prove the GIN-trigram-index candidate generation stays sub-quadratic vs. a sequential scan. The core demo and repo stand without this; if cut, the performance claim lives as README prose + the legible `CandidateGenerator` query and index migration.

## Requirements

- `php artisan seed:bulk` generates ~100,000 synthetic contacts (names, emails, phones, addresses, `name_key`/`address_key` populated).
- Lean generator — pure volume for query timing, does **not** need to reproduce hero cases or curated pairs (those live in `DemoSeeder`).
- Capture the payoff as a **committed artifact**: run the `CandidateGenerator` trigram query under `EXPLAIN ANALYZE` with the GIN index, and again with the index disabled / seq-scan forced, and save both into `docs/explain-analyze.md`.
- The artifact must show the **index scan (not nested-loop-over-everything)** — the SQL story the overview promises.

## Files to Create

1. `database/seeders/BulkSeeder.php` — 100k synthetic contacts
2. `app/Console/Commands/SeedBulk.php` — `php artisan seed:bulk`
3. `docs/explain-analyze.md` — captured EXPLAIN ANALYZE output (GIN index scan vs. seq scan), with a one-line takeaway

## Key Gotchas

- Batch-insert (chunked) to seed 100k in reasonable time — don't insert row-by-row.
- Populate `name_key`/`address_key` in bulk so the GIN index actually has data to probe.
- Force seq scan for the comparison via `SET enable_indexscan = off; SET enable_bitmapscan = off;` in a throwaway session — don't drop the real index.
- Target from the overview: **sub-100ms candidate generation at ~100k contacts.**

## Testing

No automated tests — the committed `EXPLAIN ANALYZE` artifact **is** the evidence.

## References

- `givebutter/project-overview.md` → Demo Seed Data (tier 2), Detection Algorithm (candidate generation, sub-quadratic claim)
- Depends on: `detection-phase-1-spec.md` (the query being profiled), `data-layer-spec.md`