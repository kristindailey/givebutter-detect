# Seed Data — Curated Demo Set

## Overview

`DemoSeeder` builds the ~2k-contact curated dataset the live demo runs against: the two hero cases, a small set of realistic duplicate pairs to give the Review Queue depth, and Faker noise that produces no false candidates. Deterministic so `seed:demo` re-runs the exact same data. This is the data the whole demo stands on.

## Requirements

- **Deterministic**: fix the Faker seed (`Faker::seed(2024)`) and assign **stable IDs** to the hero-case contacts so tests assert on known IDs and the demo repeats cleanly.
- **~2,000 Faker noise contacts** distinct enough that none can reach the Review band. Note the precise claim: the name-trigram block *does* emit ~4k low-similarity noise pairs at the default 0.3 threshold (Faker's surname pool is small), because blocking over-generates by design and the scorer is the filter. What must hold is that no noise pair **scores ≥ 60** — with phase 2's weights (email 30, phone 25, name 25, address 20) a name+address pair caps at 45, so crossing 60 requires a shared email or phone. No noise contact shares either with anyone.
- **Hero case 1 — Jennifer / Jen** (the catch): seeded to score ≈ 94.
- **Hero case 2 — parent / child** (the non-merge): seeded to score ≈ 35, below the Review band, never surfaced.
- **~6–8 curated near-duplicate pairs** landing in the Review band (60–89) so the queue looks real.
- Populate `name_key` / `address_key` on every contact, **and `normalized_value` on every `emails` / `phones` row**, via the Normalizer at seed time. The exact email and phone blocks self-join on `normalized_value` — leave it null and two of Detection phase 1's five blocks return nothing.
- `seed:demo` artisan command resets **just** the curated demo data so the Jennifer/Jen merge can be re-run after a dry run.

## Hero Case 1 — Jennifer / Jen (locked figures)

| | Jennifer (id 1001, survivor) | Jen (id 1002, loser) |
| --- | --- | --- |
| Name | Jennifer Smith | Jen Smith (`preferred_name` Jen) |
| Email | work@… | jen@… (personal — **different**) |
| Phone | (555)… | different |
| `dob` | same | same |
| Household | shared (id, both members) | shared |
| Transactions | $1,200 total, earliest `captured_at` 2021-06-02 | $500 succeeded @ 2019-03-14 **+ one refunded txn** |

- Different emails + different first name → **naive rule keeps them separate**.
- Fuzzy preferred-name (`Jen`≈`Jennifer` via nickname table) + shared household + `dob` agreement → prototype matches ≈ 94.
- After merge: `total_contributions` $1,200 → **$1,700** (refund excluded), `contact_since` 2021-06-02 → **2019-03-14** (the demo moment), `last_donation_amount` **$50**.
- **Blocking dependency:** Jennifer/Jen have different emails/phones and trigram(`jen`,`jennifer`)≈0.3, so the **shared household block** is what reliably generates this pair — they MUST be seeded into a shared household.
- **Tags + external IDs** are seeded on this pair only. `MergeService` unions `tags` and `external_ids` and the Merge Review screen renders both as a read-only "kept both" summary — with nothing seeded, two of the five union rows render empty on the demo's centerpiece screen. Jennifer gets `major-donor` + `board-prospect`, Jen gets `board-prospect` + `email-subscriber`: **four tags union to three**, so the summary has a dedupe to perform rather than a concatenation to display. One external ID each (`bloomerang: BLM-1001`, `mailchimp: MC-8842`) — mirrored, never matched.

## Hero Case 2 — parent / child (id 1003 / 1004)

- Share a household **email**, surname, and address.
- **Conflicting `dob`** (parent vs. child).
- Naive exact-match rule **wrongly merges** them; prototype scores ≈ 35 because the household modifier **dampens** the shared-email signal and conflicting `dob` pushes toward "different people."
- Never enters the queue (below 60).

## Curated Review-Band Pairs (~6–8)

Hand-built pairs that fire different signal combinations (email match + name typo; shared phone + fuzzy name; address trigram; etc.) landing across scores 61–88, so the queue demonstrates the per-signal "why" breakdown on varied inputs.

## Files to Create

1. `app/Services/Detection/Normalizer.php` — name/email/phone/address normalization + `name_key` / `address_key` generation
2. `config/nicknames.php` — static diminutive/alias map (read at scoring in Detection phase 2, not here)
3. `database/seeders/DemoSeeder.php` — noise + hero cases + curated pairs, deterministic
4. `app/Console/Commands/SeedDemo.php` — `php artisan seed:demo` (resets curated data only)

> **Normalizer pulled forward** from `detection-phase-1-spec.md`: the seeder can't write `name_key` / `address_key` without it, and it's pure string logic that unit-tests with no DB. Detection phase 1 keeps `CandidateGenerator` + the GIN trigram migrations, and consumes the Normalizer this feature builds. Normalization rules are specified in that spec's Normalizer table — implement against it. **The `unaccent` PHP-vs-Postgres decision is made here** and phase 1 inherits it.

## Key Gotchas

- Refunded/non-succeeded transactions must be seeded on hero case 1 so the recompute's refund-exclusion is actually proven, not assumed.
- Seed the derived fields (`total_contributions`, `contact_since`, `last_donation_amount`) to their **pre-merge** values so the before/after panel has a real "before."
- `seed:demo` resets only curated rows — don't blow away the whole DB if that's heavier than needed; but `migrate:fresh --seed` remains the full-reset path.

- **Review-band scores are provisional.** No scorer exists until Detection phase 2, and its weights are hand-tuned against the hero cases — seed and weights converge on each other. Seed the curated pairs by *signal combination*, not by target score, and expect one tuning pass after phase 2.

## Testing

A unit test covers the Normalizer directly — pure string rules, no DB.

The scoring + recompute tests (owned by the Detection and MergeService specs) run **against this seed**, asserting on the stable hero IDs. If `DemoSeeder` drifts, those tests fail — that's the coverage.

## References

- `givebutter/project-overview.md` → Demo Seed Data, recompute rules
- Depends on: `data-layer-spec.md`
- Feeds: `detection-phase-1-spec.md` (consumes the Normalizer; its hero-pair assertion runs against this seed)
- Related stretch: `seed-bulk-spec.md`