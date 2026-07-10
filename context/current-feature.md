<!-- Living document tracking the feature currently being worked on -->

# Current Feature: Merge Review — Screen 2
The single-screen merge experience: side-by-side diff, conflict-only scalar picker, auto-union array summary, and the before/after panel where the 3 derived fields recompute — the demo payoff where `contact_since` corrects itself. Hosts both outcomes: Merge (commit) and Not a duplicate (dismiss).

## Status
In Progress

## Goals

- Page loads both full contact records (arrays included) as an Inertia prop for the diff + picker
- Before/after panel fetches `GET /api/contacts/merge-preview` on mount and re-fetches only when the survivor toggle flips
- Conflict-only scalar picker: radios shown only for scalars where `conflict: bool` is true; identical fields hidden; default = survivor's value
- Survivor auto-proposed by `MergeService`; header dropdown lets user override (re-fetches preview swapped)
- Arrays (`emails`, `phones`, `addresses`, `tags`, `external_ids`) render as read-only auto-union "both kept" summary
- Before/after panel shows `contact_since`, `total_contributions`, `last_donation_amount` before→after
- Micro-interaction: CSS keyframe flash (brand cream/yellow) on the changed `contact_since` after-value, firing on preview data arrival (not mount)
- Merge → `POST /api/contacts/merge` with `{survivor_id, loser_id, picks}`; no optimistic UI — wait for server, toast success, redirect to queue
- Not a duplicate → `POST /candidates/{id}/dismiss` (Inertia), redirect to queue
- Cancel → back to queue, no mutation

## Notes

Files to create/touch:
1. `resources/js/Pages/MergeReview.tsx` — page composition + fetch/commit logic
2. `resources/js/components/FieldPicker.tsx` — conflict-only scalar picker
3. `resources/js/components/ArrayUnionSummary.tsx` — read-only "both kept"
4. `resources/js/components/BeforeAfterPanel.tsx` — derived diff + `contact_since` flash
5. `resources/js/components/SurvivorToggle.tsx` — header override dropdown
6. `app/Http/Controllers/ContactController.php` — Inertia prop with both full records (or fold into DuplicateController)
7. `routes/web.php` — the merge-review page route
8. CSS keyframes for the highlight flash

Gotchas:
- Only conflicting scalars get a picker — compute from projection's `scalars` (each carries `conflict: bool`).
- Commit sends `picks`; server still defaults unspecified fields to survivor — don't rely on client sending all.
- Flash fires on preview data arrival, not page mount (data is async) — key animation off resolved preview state.
- Wait for merge POST before toasting (no optimistic UI) — merge is destructive/high-trust.
- Case 2 (parent/child) never reaches this screen via the queue (below band).

Depends on: `merge-service-spec.md`, `merge-api-spec.md`, `review-queue-spec.md` (AppShell), `foundation-spec.md`. No UI tests — manual verification via Jennifer/Jen path.

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
