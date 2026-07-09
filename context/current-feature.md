<!-- Living document tracking the feature currently being worked on -->

# Current Feature: Seed Data — Curated Demo Set
<!-- Title above as "# Current Feature: <name>", followed by a one- or two-sentence description of the feature/fix. -->

`DemoSeeder` builds the ~2k-contact curated dataset the live demo runs against: the two hero cases, ~6–8 review-band pairs for queue depth, and Faker noise that produces no false candidates. Deterministic, so `seed:demo` re-runs the exact same data. Lands the `Normalizer` (pulled forward from Detection phase 1) since the seeder can't write blocking keys without it.

## Status
In Progress

## Goals

- [x] `app/Services/Detection/Normalizer.php` — name/email/phone/address normalization + `name_key` / `address_key` generation
- [x] `config/nicknames.php` — static diminutive/alias map (consumed at scoring in Detection phase 2, not at blocking)
- [x] Unit test for the Normalizer — pure string rules, no DB (22 cases)
- [x] `database/seeders/DemoSeeder.php` — deterministic (`Faker::seed(2024)`), stable hero IDs
- [x] ~2,000 Faker noise contacts that generate no *queue-eligible* pairs — see the trigram note below
- [x] Hero case 1 — Jennifer (id 1001) / Jen (id 1002), shared household, same `dob`, different emails + phones
- [x] Hero case 1 transactions: Jennifer $1,200 (earliest `captured_at` 2021-06-02); Jen $500 succeeded @ 2019-03-14 **plus one refunded $250**
- [x] Hero case 2 — parent (1003) / child (1004): shared household email, surname, address; conflicting `dob`
- [x] 7 curated near-duplicate pairs (ids 1005–1018) firing varied signal combinations
- [x] `name_key` / `address_key` on every contact, and `normalized_value` on every `emails` / `phones` row, written by the Normalizer at seed time
- [x] Hero case 1 tags + external IDs — 4 tags across the pair, one shared (unions to 3); one external ID each, mirrored not matched
- [x] `app/Console/Commands/SeedDemo.php` — `php artisan seed:demo` resets the demo domain tables, leaving the demo admin
- [x] `tests/Feature/DemoSeederTest.php` — pins the locked hero figures and the no-noise-collision guard

## Notes

- **Blocking dependency (hero case 1):** different emails/phones and trigram(`jen`,`jennifer`) ≈ 0.3, so the **shared-household block** is what reliably generates the pair. Both must be seeded into one household.
- **Post-merge targets** (what the before/after panel must show): `total_contributions` $1,200 → **$1,700** (refund excluded), `contact_since` 2021-06-02 → **2019-03-14** (the demo moment), `last_donation_amount` **$50**.
- Seed the derived fields (`total_contributions`, `contact_since`, `last_donation_amount`) to their **pre-merge** values so the before/after panel has a real "before."
- The refunded transaction on hero case 1 is what *proves* the refund-exclusion rule rather than assuming it.
- `seed:demo` resets curated rows only; `migrate:fresh --seed` stays the full-reset path.
- **Normalizer pulled forward** from `detection-phase-1-spec.md`: the seeder can't write `name_key` / `address_key` without it, and it's pure string logic that unit-tests with no DB. `CandidateGenerator` + the GIN trigram index migrations stay in Detection phase 1, which then lands on a populated DB and can assert the hero pair survives blocking on its first run.
- `unaccent` is a separate Postgres extension. Doing it in PHP avoids the dependency — pick one and stay consistent (Detection phase 1 reuses this).
- **Review-band scores are provisional.** No scorer exists until Detection phase 2, and its weights are hand-tuned against the hero cases — seed and weights converge on each other. Seed the ~6–8 curated pairs by *signal combination* (email match + name typo; shared phone + fuzzy name; address trigram), not by target score. Expect one tuning pass after phase 2. The two hero cases are specified concretely enough to seed correctly now.
- Testing is otherwise owned by the Detection and MergeService specs — those tests assert on the stable hero IDs against this seed. Seeder drift breaks them; that's the coverage.
- `normalized_value` on `emails` / `phones` is easy to forget — the exact email + phone blocks self-join on it, so a null column silently kills two of Detection phase 1's five blocks and the review-band pairs that fire on them.
- **"Noise generates no duplicate candidates" was too strong, as measured.** At the default `pg_trgm` threshold of 0.3, the name-trigram block emits **4,045 noise pairs** (Faker's surname pool is small; `name_key` is dominated by the surname, so `cade predovic` ~ `caden predovic` = 0.81). Blocking is *supposed* to over-generate — the scorer is the filter. What actually holds, and is now enforced: **no noise pair can reach the Review band.** Phase 2 weights are email 30, phone 25, name 25, address 20, so a name+address pair tops out at **45 < 60**; crossing 60 needs a shared email or phone, and no noise contact shares either with anyone. Verified against Postgres (noise ceiling 25, zero pairs ≥ 60) and pinned on SQLite by the exact-block collision test.
- **Unaccent decision (inherited by Detection phase 1): PHP-side**, via `intl`'s `Transliterator`. Keys are folded before storage, so the trigram blocks compare ASCII and SQL never calls `unaccent()`. The Postgres extension stays enabled but unused.
- Score targets (Jennifer/Jen ≈ 94, parent/child ≈ 35, curated pairs 61–88) are **not asserted here** — no scorer exists yet. They are Detection phase 2's acceptance targets, tested against this seed.
- **The test suite now runs on Postgres**, not in-memory SQLite — a matcher built on `pg_trgm` cannot be tested on a database that lacks it. `phpunit.xml` points at `givebutter_detect_testing`, which `Tests\TestCase::beforeRefreshingDatabase()` creates on first run so `composer test` still works from a clean checkout. The `DB_CONNECTION` fallbacks in `config/database.php` and `config/queue.php` now say `pgsql` instead of silently dropping to SQLite.
- The exhaustive noise-ceiling proof (enumerate every blocked pair, take the max score ceiling) is **not** a test: without the GIN trigram indexes a single trigram self-join is ~2.5s. It moves to Detection phase 1, which owns those indexes. `DemoSeederTest` instead pins the two cheap preconditions the argument rests on — no noise contact shares an email or phone, and none belongs to a household.
- Spec: `context/features/seed-demo-spec.md` (now owns the Normalizer; normalization rules are tabled in `context/features/detection-phase-1-spec.md`)

## History
<!-- Title of feature/fix and brief description of feature/fix -->

### Foundation & Scaffold
Stood up the full stack: stripped starter-kit auth to a seeded demo admin, Postgres 16 Docker Compose, `pg_trgm` migration, Givebutter brand theming, and a `/health` gate.

### Data Layer — Migrations & Models
Eleven migrations and nine models mirroring Givebutter's verified API schema, with `archived_at` soft-delete, blocking-key columns, and mass-assignment guards on the derived giving fields. Added `tags` after checking the ERD against the real OpenAPI spec.
