# Detection Phase 1 — Normalization & Candidate Generation

## Overview

The blocking half of the matcher: deterministic normalization, the GIN trigram indexes, and the `CandidateGenerator` that emits a small set of canonical candidate pairs via cheap blocking keys instead of scoring all ~5B pairs. Output is an in-memory collection of deduped `(a.id < b.id)` pairs handed to Phase 2 for scoring. This phase owns the SQL story the `EXPLAIN ANALYZE` artifact proves.

## Requirements

### Normalizer (deterministic, applied before blocking + scoring)

A `Normalizer` service producing the stored `name_key` / `address_key` and the field-level normalized forms used in scoring:

| Field | Rule |
| ----- | ---- |
| Names | lowercase, `unaccent`, strip non-alpha, collapse whitespace → `name_key` |
| Emails | lowercase + trim (plus-addressing / gmail-dot canonicalization noted as future, not built) |
| Phones | strip to digits, compare on last 10 (US) |
| Addresses | lowercase, `unaccent`, strip punctuation, collapse whitespace; key = `address_1 + city + zipcode` → `address_key` (from the **primary** address) |

- **Nickname table** lives in `config/nicknames.php` (static map, e.g. `jennifer => [jen, jenny]`). Used at **scoring only** (Phase 2), not at blocking.

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

1. `app/Services/Detection/Normalizer.php` — name/email/phone/address normalization + key generation
2. `config/nicknames.php` — static diminutive/alias map
3. `app/Services/Detection/CandidateGenerator.php` — the five blocking self-joins, union + canonical dedupe, returns `Collection<[a_id, b_id]>`
4. `database/migrations/xxxx_add_trgm_indexes.php` — GIN `gin_trgm_ops` on `name_key`, `address_key`

## Key Gotchas

- `unaccent` is a separate Postgres extension — enable it alongside `pg_trgm` if the name/address normalization uses it in SQL (or do the unaccent in PHP to avoid the dependency; pick one and be consistent).
- Canonical ordering (`a.id < b.id`) must be applied **inside each block's SQL** (`WHERE a.id < b.id`) so the union dedupes cleanly.
- **Noted, not built** (document, don't implement — synthetic seed doesn't trigger them): skip degenerate blocking values (NULL/blank, shared `info@` inboxes, `(000) 000-0000`); cap block size (a value shared by >~50 contacts is non-discriminating).
- Candidates are strictly **pairwise** — A≈B and B≈C produce two independent pairs, not a 3-way cluster (transitive clustering is deferred).

## Testing

Folded into the Detection Phase 2 test (scoring runs on generated candidates). A focused assertion here: the Jennifer/Jen pair **is present** in `CandidateGenerator->generate()` output (proves the household block didn't drop the hero pair).

## References

- `givebutter/project-overview.md` → Detection Algorithm (candidate generation, normalization), Data Architecture
- Depends on: `data-layer-spec.md` (name_key/address_key columns), `foundation-spec.md` (pg_trgm)
- Feeds: `detection-phase-2-spec.md`