# Detection Phase 1 — Normalization & Candidate Generation

## Overview

The blocking half of the matcher: the GIN trigram indexes and the `CandidateGenerator` that emits a small set of canonical candidate pairs via cheap blocking keys instead of scoring all ~5B pairs. Output is an in-memory collection of deduped `(a.id < b.id)` pairs handed to Phase 2 for scoring. This phase owns the SQL story the `EXPLAIN ANALYZE` artifact proves.

> **Normalizer moved.** `app/Services/Detection/Normalizer.php` and `config/nicknames.php` ship with `seed-demo-spec.md` — `DemoSeeder` cannot write `name_key` / `address_key` without them, and they're pure string logic that unit-tests with no DB. This phase **consumes** the Normalizer; it does not build it. Its rules are restated below as the contract this phase depends on.

## Requirements

### Normalizer (built in `seed-demo-spec.md` — contract this phase relies on)

The `Normalizer` service produces the stored `name_key` / `address_key` and the field-level normalized forms used in scoring:

| Field | Rule |
| ----- | ---- |
| Names | lowercase, `unaccent`, strip non-alpha, collapse whitespace → `name_key` |
| Emails | lowercase + trim (plus-addressing / gmail-dot canonicalization noted as future, not built) |
| Phones | strip to digits, compare on last 10 (US) |
| Addresses | lowercase, `unaccent`, strip punctuation, collapse whitespace; key = `address_1 + city + zipcode` → `address_key` (from the **primary** address) |

- **Nickname table** lives in `config/nicknames.php` (static map, e.g. `jennifer => [jen, jenny]`). Used at **scoring only** (Phase 2), not at blocking — so nothing in this phase reads it.

### Candidate Generation — blocking, not O(n²)

`CandidateGenerator->generate()` runs these blocks as **raw SQL self-joins**, unions the results, dedupes to canonical `(a.id < b.id)` pairs, and returns a `Collection` of pairs (persists nothing):

| Block | Mechanism |
| ----- | --------- |
| Exact normalized email | self-join on normalized `emails.value`, btree index |
| Exact normalized phone | self-join on normalized `phones.value`, btree index |
| Trigram-similar name | GIN `gin_trgm_ops` on `name_key` + `%` operator (`a.name_key % b.name_key`) |
| Trigram-similar address | GIN `gin_trgm_ops` on `address_key` + `%` operator |
| Same household | join on `household_contacts` |

- Raw SQL (not Eloquent) so the trigram/GIN mechanics are legible — this query is the artifact an async reviewer reads.
- **Hero dependency:** Jennifer/Jen have different emails/phones and trigram(`jen`,`jennifer`)≈0.3, so the **same-household block reliably generates this pair.** Do not let candidate generation drop it. (Nickname expansion is deliberately NOT added at blocking — the household block carries it.)

### GIN trigram index migrations (owned here)

- GIN `gin_trgm_ops` index on `contacts.name_key`
- GIN `gin_trgm_ops` index on `contacts.address_key`

(The `pg_trgm` extension is enabled in the Foundation spec, before these run.)

## Files to Create

1. `app/Services/Detection/CandidateGenerator.php` — the five blocking self-joins, union + canonical dedupe, returns `Collection<[a_id, b_id]>`
2. `database/migrations/xxxx_add_trgm_indexes.php` — GIN `gin_trgm_ops` on `name_key`, `address_key`

(`Normalizer.php` and `config/nicknames.php` are built in `seed-demo-spec.md`.)

## Key Gotchas

- `unaccent` is a separate Postgres extension. The PHP-vs-SQL decision is made in `seed-demo-spec.md` when the Normalizer lands — **match it here**, don't re-litigate it.
- Canonical ordering (`a.id < b.id`) must be applied **inside each block's SQL** (`WHERE a.id < b.id`) so the union dedupes cleanly.
- **Noted, not built** (document, don't implement — synthetic seed doesn't trigger them): skip degenerate blocking values (NULL/blank, shared `info@` inboxes, `(000) 000-0000`); cap block size (a value shared by >~50 contacts is non-discriminating).
- Candidates are strictly **pairwise** — A≈B and B≈C produce two independent pairs, not a 3-way cluster (transitive clustering is deferred).

## Testing

Folded into the Detection Phase 2 test (scoring runs on generated candidates). A focused assertion here: the Jennifer/Jen pair **is present** in `CandidateGenerator->generate()` output (proves the household block didn't drop the hero pair). This runs against `DemoSeeder`'s stable hero IDs, so it is real on its first run — the reason the seed lands before this phase.

Two assertions inherited from `seed-demo-spec.md`, both of which need this phase's GIN indexes to run fast enough to be tests (an unindexed trigram self-join over the 2k seed is ~2.5s):

1. **No noise pair reaches the Review band.** Enumerate every blocked pair involving a contact with `id > 1018`, sum the phase 2 weights for the blocks that fired, assert the max ceiling is `< 60`. Measured at **25** against the current seed.
2. **The name-trigram block over-generates on noise** (~4k pairs at the default 0.3 threshold) — this is blocking working as intended, and asserting it keeps (1) from being vacuous.

The suite runs on Postgres (`givebutter_detect_testing`), so `%` and `similarity()` are available in tests.

## References

- `givebutter/project-overview.md` → Detection Algorithm (candidate generation, normalization), Data Architecture
- Depends on: `data-layer-spec.md` (name_key/address_key columns), `foundation-spec.md` (pg_trgm), `seed-demo-spec.md` (Normalizer + the seeded hero pair this phase's test asserts on)
- Feeds: `detection-phase-2-spec.md`