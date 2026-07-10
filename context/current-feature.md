<!-- Living document tracking the feature currently being worked on -->

# Current Feature: Merge API — Preview (dry-run) & Commit

The two JSON API routes — a dry-run `GET /api/contacts/merge-preview` and a committing `POST /api/contacts/merge` sharing one `MergeService->project()` projection — plus the Inertia `dismiss` action for "not a duplicate".

## Status
In Progress

## Goals

- `GET /api/contacts/merge-preview?survivor={id}&loser={id}` — dry run: calls `project(commit=false)`, returns the projection DTO, commits nothing. Powers the before/after panel.
- `POST /api/contacts/merge` — commit: request `{ survivor_id, loser_id, picks }`; validates both contacts exist, `type = individual`, `survivor_id != loser_id`, picks reference real conflicting scalar fields; calls `project(commit=true)`; sets candidate `resolution='merged'`, `resolved_at=now()`; returns committed projection.
- `POST /candidates/{candidate}/dismiss` — Inertia action (not JSON): sets `resolution='dismissed'`, `resolved_at=now()`, redirects back to Review Queue. The labeled negative that trains scoring weights in production.
- Shared projection guarantee: preview and commit call the same `MergeService->project()` — preview == commit by construction.

## Notes

**Files to create:**
- `app/Http/Controllers/MergeController.php` — `preview()` (GET) + `commit()` (POST)
- `app/Http/Controllers/DuplicateController.php` — `dismiss()` Inertia action
- `app/Http/Requests/MergeRequest.php` — validation for the commit POST
- `routes/api.php` — the two JSON routes
- `routes/web.php` — the dismiss action

**Gotchas:**
- Merge POST must be idempotent-ish against a stale queue: if the candidate is already resolved, return a clean 409/conflict rather than double-merging.
- `picks` default to survivor values server-side — never trust the client to send every field.
- Keep JSON routes free of the `archived` global-scope surprise: loading the loser for merge must see it.

**Testing:** No endpoint-integration tests per the overview — projection/recompute correctness is covered by the MergeService test; the API layer is thin over the tested service.

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
