<!-- Living document tracking the feature currently being worked on -->

# Current Feature: Dismiss In-Flight State + Failure Toast

The two UX gaps left by the dismiss-redirect fix, both in `handleDismiss` (`resources/js/pages/merge-review.tsx:152`). Merge tracks its request and toasts what went wrong; dismiss does neither — it fires and hopes.

## Status
In Progress

## Goals

- A failed dismiss toasts a user-friendly error and leaves the reviewer on the page to retry, instead of Inertia's default bounce to an error modal.
- "Not a duplicate" disables itself while its own request is in flight, mirroring how Merge reads `Merging…` and disables.
- A dismiss in flight also disables Merge and the survivor toggle, the same way a merge in flight already disables dismiss.

## Notes

- **The `onError` I flagged was wrong.** Verified against the v3 docs: `onError` fires only "when validation errors are present on successful page visits" — it and `onSuccess` are two branches of the same successful-response path, split on whether an errors bag is present. Dismiss has no validation and always redirects, so an `onError` handler would be dead code. Inertia v3's per-visit `onHttpException(response)` (4xx/5xx) and `onNetworkError(error)` are the correct hooks; they were v2's global `invalid`/`exception` events.
- Returning `false` from `onHttpException` "prevents Inertia from navigating to the error page, allowing for in-page error handling" (upgrade guide, verbatim). That's what keeps the reviewer on the merge screen with a toast.
- `onFinish` "fires after an XHR request has completed for both successful and unsuccessful responses" and the docs nominate it for hiding loading indicators — so `onStart`/`onFinish` is the right pair for the in-flight state. One wrinkle: returning a promise from `onSuccess`/`onError` *delays* `onFinish` until it resolves. Ours return nothing, so this doesn't bite, but don't start returning promises from them.
- Nothing configures `Inertia::handleExceptionsUsing()`, so today a failed dismiss falls through to Inertia's default error modal. That's the behavior being replaced.
- **Do not read the response body for the toast copy.** `lib/merge.ts` deliberately reads `message` only for the guard statuses (404/409/422) so an unplanned failure can't toast internals like "No query results for model [App\Models\Contact] 1002." Dismiss has no guard statuses of its own — it's idempotent and always redirects — so it gets one fixed generic message.
- Realistic failure modes: a 419 (session expired on a demo tab left open overnight), a 404 (a candidate id that no longer exists after a reseed — the scheduled reset renumbers `duplicate_candidates`), a 500.
- Add a `dismissing` state next to the existing `committing`. Both buttons and `SurvivorToggle` gate on `committing || dismissing`; `onStart`/`onFinish` drive it.
- **No Pest test.** Project convention is deliberate: "the matcher and the money-math are tested; the UI is prototype-grade." Verify in the browser; the gate covers TypeScript and lint. Forcing the 419/500 path is a manual check.
- The success toast survives the redirect because `<Toaster />` is mounted in `app.tsx` at the root, above the swapped page component — not because of any documented `onSuccess`-vs-render ordering, which the v3 docs are silent on. The failure toasts inherit that same persistence, so a toast fired from `onHttpException` is safe whether or not the page changes.

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

### Dismiss Redirects to the Review Queue
"Not a duplicate" stranded the reviewer on the pair they had just resolved: `dismiss` returned `back()`, and its only caller is the Merge Review page. The dismissal always committed, so only the navigation was wrong — `to_route('duplicates.index')` matches merge, cancel, and `DemoResetController`. The existing tests called `markDismissed()` directly and never hit the route, which is how the wrong target went unnoticed.
