<!-- Living document tracking the feature currently being worked on -->

# Current Feature
<!-- Title above as "# Current Feature: <name>", followed by a one- or two-sentence description of the feature/fix. -->

## Status
Not Started

## Goals

<!-- Bullet points of what success looks like -->

## Notes

<!-- Additional context, constraints, or details from spec -->

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
The single-screen merge over the shared `project()` projection: conflict-only picker, read-only array union, and the before/after panel.

### Merge Guard — Pending Candidate + Archived Rejection
The commit route would merge any two individual contacts: a null candidate skipped the resolved-pair guard (`$candidate !== null && …`), and nothing rejected a contact a previous merge had archived. Now 404 on an undetected pair, 422 on an archived one, and the client toasts which guard fired. Both guards are needed — pairs are pairwise, so B≈C stays pending after A≈B archives B, and only validation catches a survivor override onto B.

### Demo Reset — Scheduled Reset, Reset Button, Idempotent detect:run
`seed:demo --detect` resets the shared demo in one command, fired by a 10-minute schedule (only when someone has touched it) and a flag-gated top-bar button. Building it surfaced two bugs: `detect:run` resurrected merged and dismissed pairs, and a missing `ANALYZE` made the first post-seed run 11.4s instead of 779ms.

### Deploy Readiness — seed:demo on a Fresh Database
The release runs `migrate --force` + `seed:demo`, which couldn't seed an empty database: Faker was dev-only, and only `DatabaseSeeder` made the demo admin. Faker moves to `require`; a new `DemoAdminSeeder` backs both paths.

### Batched Trigram Similarity
`PairScorer` asked Postgres for one similarity per pair, which is ~1s on localhost but 22s+ over the network, so the deployed reset button read as a timeout. `TrigramSimilarity` now resolves them in one query per 1,000 pairs, scores unchanged.

### VACUUM After Seeding
The deployed reset button took ~20s, and it was neither the scorer nor CPU: a bulk insert leaves Postgres' visibility map unset, so the first post-seed `detect:run` scanned cold rows. `VACUUM (ANALYZE)` instead of `ANALYZE` took the full reset from 14s to 2.3s.

### Dismiss Redirects to the Review Queue
"Not a duplicate" stranded the reviewer on the pair they had just resolved: `dismiss` returned `back()`, and its only caller is the Merge Review page. The dismissal always committed, so only the navigation was wrong — `to_route('duplicates.index')` matches merge, cancel, and `DemoResetController`. The existing tests called `markDismissed()` directly and never hit the route, which is how the wrong target went unnoticed.

### Dismiss In-Flight State + Failure Toast
A failed dismiss said nothing and bounced to Inertia's error page; an in-flight one left every control live. The fix hangs on a docs detail: `onError` fires only for validation errors, so the obvious handler would have been dead code — `onHttpException`/`onNetworkError` returning `false` are what toast and hold the page. A `dismissing` state now relabels the button and gates Merge and the survivor toggle.

### Reset Button's Dead onError
The same dead `onError` in the demo reset button, where a 429 is the realistic failure — the throttle exists because a live demo is what trips it. A/B against an intercepted 429: no toast at all, and Inertia covering the screen with "All Inertia requests must receive a valid Inertia response…" in front of an audience. The throttle now gets named copy ("wait a minute"), branched off the status line so an unplanned 500 still can't toast internals. Merge, dismiss, and reset are the app's only three client→server actions; all three now report failure.

### FieldPicker In-Flight State
The picker's choice cards stayed live while a merge or dismiss was underway, alone among the screen's controls. Cosmetic only — `picks` is captured at click time, so a card pressed mid-request could never have changed what commits. The hover class is dropped while disabled rather than overridden, since a `disabled:hover:` rule would have to name the idle background and would lie the day it changed. Every control on Merge Review now gates on the in-flight state except Cancel and Back to queue, which stay live on purpose.

### Remove the contact_since Flash Animation
Deleted the yellow→cream keyframe that flashed the corrected `contact_since` on each fresh preview, plus its dead plumbing (`flashKey`, `flashOnChange`/`shouldFlash`, the `value-flash` keyframe + class). The changed after-values still read in brand blue.
