<!-- Living document tracking the feature currently being worked on -->

# Current Feature: Demo Reset — Scheduled Reset, Idempotent `detect:run`, Reset Button
<!-- Title above as "# Current Feature: <name>", followed by a one- or two-sentence description of the feature/fix. -->

The demo is being deployed to a public URL, so one database is shared by every visitor and nobody has a terminal. The first person to merge Jennifer/Jen archives her permanently and the headline case is gone for everyone after them. Make the demo self-heal on a schedule, make `detect:run` safe to rerun, and give the deployed app a way to reset itself.

## Status
Complete

## Goals

- **`seed:demo --detect`** — one command for a full reset. The scheduler, the reset button, and the deploy release command all call this same thing. `detect:run` stays a standalone command; the README's two-step first-run flow stays as-is, because "the queue is empty until the batch scorer runs" is a deliberate demonstration that scoring is a batch job, not page-load work.

- **`ANALYZE` after seeding** — the seeder bulk-inserts every table and leaves Postgres with no planner statistics, so the first `detect:run` after a seed picks a bad plan and takes **11.4s instead of 779ms** on 2,018 contacts (measured; see Notes). One `ANALYZE` at the end of `DemoSeeder` fixes it for every path — the scheduled reset, the button, and `migrate:fresh --seed`.

- **`detect:run` becomes idempotent** — a rerun rescores current data without undoing decisions already made:
  - Add `archived_at IS NULL` to all five blocks in `CandidateGenerator::SQL`. An archived contact is a merge loser and must never generate a new candidate pair.
  - Upsert into `duplicate_candidates` instead of truncate-and-reinsert. Existing pairs keep their `resolution`; scores and `signal_breakdown` refresh in place.

- **Scheduled reset every 10 minutes** — `schedule()->command('seed:demo --detect --force')`. This is the safety net that matters: a visitor who merges the hero pair and walks away doesn't leave the demo broken for the next one. It fires **only on a demo someone has used** (a resolved candidate or an archived contact): reseeding renumbers `duplicate_candidates`, so an unconditional reset would move `/duplicates/{id}` out from under anyone reading a Merge Review page — every ten minutes, forever, even on an idle demo.

- **Minimal in-app reset button** — a "Reset demo data" control in the `AppShell` header, POSTing to one route that runs the same command inline. This one is for driving a live demo from the deployed URL, where waiting out a cron isn't an option.

- **README** — document that the deployed demo resets itself every 10 minutes.

## Notes

**The `detect:run` bug this fixes.** `DetectRun` truncates `duplicate_candidates` and re-inserts every pair without setting `resolution`, which defaults to `pending` (`create_duplicate_candidates_table.php:31`). `CandidateGenerator::SQL` never filters archived contacts — an archived Jen keeps her rows in `emails`, `phones`, and `household_contacts`, so the household block still emits the pair. Net effect: rerunning `detect:run` after a merge resurrects the merged pair as `pending`, pointing at an archived contact, which the merge guard then 422s — a queue row nobody can act on. Dismissals come back the same way. Today a `seed:demo` always precedes it, which hides this; on a deployed URL with a scheduler it stops being hidden.

**Why upsert rather than truncate.** The spec already says resolved rows are kept, not deleted, and `DetectRun`'s own docstring already claims a rerun "always reflects the current data." The two commands should mean two different, honest things: `seed:demo --detect` is "back to zero," `detect:run` is "rescore without undoing my decisions."

**Button scope — keep it minimal.** One rate-limited POST route, run inline (no queue, no job, no "resetting…" state), gated behind a config flag so it can be turned off. Gate on an explicit `DEMO_MODE` env flag, **not** `app()->environment('local')` — a deployed demo *is* production, so an environment-name check would either disable the button or force the environment to lie.

**Timing (measured, 2,018 contacts).** `seed:demo` 1.0s + `ANALYZE` 1.2s + `detect:run` 0.8s ≈ **3s** for a full reset. That runs inline in a request comfortably, so the button needs no queue, no job, and no "resetting…" state.

**The stale-statistics finding.** Before the `ANALYZE`, the first `detect:run` after a seed took **12.8s** — 11.4s of it in `CandidateGenerator::generate()`. The query is not the problem: `EXPLAIN (ANALYZE, BUFFERS)` shows the GIN index doing its job (`Bitmap Index Scan on contacts_name_key_trgm`) with an 801ms actual execution, and a warm rerun is ~780ms. The 14× gap is purely stale planner stats after the seeder's bulk delete-and-insert; autovacuum eventually catches up, which is why this never showed up in normal use. It matters because the README's setup steps are `migrate:fresh --seed && detect:run` — so **every reviewer who clones the repo hits the 12s path**, immediately after reading that candidate generation is sub-100ms at 100k contacts.

## Deploying to Laravel Cloud

Three steps. `composer deploy` (added in this feature) wraps the two commands, so the deploy command field is one line.

1. **Deploy command:** `composer deploy` → `migrate --force` then `seed:demo --detect --force`. Every deploy lands on a clean demo with a scored queue; without it the queue is empty on first boot.
2. **Scheduler:** enable the **Scheduler** toggle on the App compute cluster, then redeploy. It is **off by default** — verified against Laravel Cloud's docs — and it is what invokes `schedule:run` every minute. Without it the 10-minute reset silently never fires, and the first thing that tells you is a broken demo.
3. **Environment:** `DEMO_RESET_ENABLED=true` (the default) keeps the scheduled reset and the "Reset demo" button on. Set it `false` to serve the demo read-only.

**`pg_trgm` is fine.** Laravel Cloud's Serverless Postgres is Neon-backed, and Neon ships `pg_trgm` (v1.6) installable via `CREATE EXTENSION` — so the existing extension migration runs unchanged. This was the one deploy risk worth checking, and it clears.

## History
<!-- Title of feature/fix and brief description of feature/fix -->

### Foundation & Scaffold
Stood up the full stack: stripped starter-kit auth to a seeded demo admin, Postgres 16 Docker Compose, `pg_trgm` migration, Givebutter brand theming, and a `/health` gate.

### Data Layer — Migrations & Models
Eleven migrations and nine models mirroring Givebutter's verified API schema, with `archived_at` soft-delete, blocking-key columns, and mass-assignment guards on the derived giving fields. Added `tags` after checking the ERD against the real OpenAPI spec.

### Seed Data — Curated Demo Set
`DemoSeeder` builds 2,018 deterministic contacts: two hero cases at stable IDs, seven review-band pairs, and noise that can't reach the Review band. Pulled the `Normalizer` forward and moved the suite onto Postgres, since `pg_trgm` can't be tested on SQLite.

### Detection Phase 1 — Candidate Generation
`CandidateGenerator` unions five blocking self-joins (exact email/phone, trigram name/address, same household) into canonical deduped pairs, backed by new GIN `gin_trgm_ops` indexes. The household block carries the Jennifer/Jen hero pair the name/email blocks drop.

### Detection Phase 2 — Scoring & detect:run
`PairScorer` scores each candidate 0–100 with a `signal_breakdown` and the asymmetric household modifier (dampen/boost/conflict), landing both hero cases (Jennifer/Jen 94, parent/child ~35); `detect:run` batch-scores the set into `duplicate_candidates`.

### MergeService — Shared Preview/Commit Projection
One `project()` backs both preview and commit, with survivor proposal, three-tier field resolution (scalar picker + gap-fill, array union, derived recompute), and re-point + archive-last in one DB transaction.

### Merge API — Preview/Commit Routes & Dismiss
Two JSON routes sharing `project()` (dry-run `GET merge-preview`, committing `POST merge`) plus the Inertia `dismiss` action, on the `web` group for the shared session. Server-side picks whitelist and a 409 guard on resolved pairs.

### Review Queue — Screen 1
Ranked pending pairs (score ≥ review band) as an Inertia prop inside a shared Givebutter `AppShell`, with band badges and why-chips read straight from `signal_breakdown`. Rows link to a stubbed Merge Review route.

### Merge Review — Screen 2
The single-screen merge over the shared `project()` projection: conflict-only picker, read-only array union, and the before/after panel that flashes `contact_since` correcting itself.

### Merge Guard — Pending Candidate + Archived Rejection
The commit route would merge any two individual contacts: a null candidate skipped the resolved-pair guard (`$candidate !== null && …`), and nothing rejected a contact a previous merge had archived. Now 404 on an undetected pair, 422 on an archived one, and the client toasts which guard fired. Both guards are needed — pairs are pairwise, so B≈C stays pending after A≈B archives B, and only validation catches a survivor override onto B.
