<!-- Living document tracking the feature currently being worked on -->

# Current Feature: Dismiss Redirects to the Review Queue

"Not a duplicate" on the Merge Review page leaves you on `/duplicates/{id}` instead of returning to the queue, unlike Merge and Cancel. `DuplicateController@dismiss` returns `back()`, and from the Merge Review page the referrer *is* that page — so Inertia re-renders it.

## Status
In Progress

## Goals

- `dismiss` redirects to the Review Queue (`duplicates.index`), matching the outcome of Merge and Cancel.
- The success toast still fires on arrival at the queue, and the dismissed pair is gone from it.
- A Pest test covers the redirect target, so the endpoint's response — not just its side effect — is pinned.

## Notes

- The bug is server-side, at `app/Http/Controllers/DuplicateController.php:89`. The docblock on line 81 already claims "Redirects back to the Review Queue" — the intent was there; `back()` doesn't honor it. Fix the response, not the client.
- Existing tests cover `markDismissed()`'s side effect (`DataLayerGuardsTest`, `DetectRunTest`) but never hit the route, which is why the wrong redirect target went unnoticed.
- The idempotency guard (an already-resolved pair is left untouched) stays as-is — it should redirect to the queue either way.
- `handleDismiss` in `resources/js/pages/merge-review.tsx:152` needs no change: `router.post` follows the redirect, and `onSuccess` fires after the queue renders.

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

### Demo Reset — Scheduled Reset, Reset Button, Idempotent detect:run
`seed:demo --detect` resets the shared demo in one command, fired by a 10-minute schedule (only when someone has touched it) and a flag-gated top-bar button. Building it surfaced two bugs: `detect:run` resurrected merged and dismissed pairs, and a missing `ANALYZE` made the first post-seed run 11.4s instead of 779ms.

### Deploy Readiness — seed:demo on a Fresh Database
The release runs `migrate --force` + `seed:demo`, which couldn't seed an empty database: Faker was dev-only, and only `DatabaseSeeder` made the demo admin. Faker moves to `require`; a new `DemoAdminSeeder` backs both paths.

### Batched Trigram Similarity
`PairScorer` asked Postgres for one similarity per pair, which is ~1s on localhost but 22s+ over the network, so the deployed reset button read as a timeout. `TrigramSimilarity` now resolves them in one query per 1,000 pairs, scores unchanged.

### VACUUM After Seeding
The deployed reset button took ~20s, and it was neither the scorer nor CPU: a bulk insert leaves Postgres' visibility map unset, so the first post-seed `detect:run` scanned cold rows. `VACUUM (ANALYZE)` instead of `ANALYZE` took the full reset from 14s to 2.3s.
