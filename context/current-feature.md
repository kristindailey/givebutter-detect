<!-- Living document tracking the feature currently being worked on -->

# Current Feature: Reset Button's Dead onError

`ResetDemoButton` (`resources/js/layouts/AppShell.tsx:96`) handles failure with `onError` on a route that has no validation, so its toast has never fired. A throttled reset drops a full-screen framework diagnostic over the demo — the exact outcome the route's own throttle comment says it's trying to avoid.

## Status
In Progress

## Goals

- A failed demo reset toasts and keeps the user where they are, instead of Inertia's error page. A 429 (the realistic case) is the one to verify.

## Notes

- **`onError` is dead code at `AppShell.tsx:96`.** Same bug just fixed in `handleDismiss`: `onError` fires only for validation errors on otherwise-successful visits. `DemoResetController` aborts 404 (flag off), 429s (throttled), or redirects — never validation. Replace with `onHttpException`/`onNetworkError` returning `false`, exactly as `handleDismiss` now does.
- **The 429 is why this matters.** `routes/web.php` throttles the route at 15/min and its comment says the limit is loose on purpose because "the person most likely to hit the limit is whoever is driving a live demo, and a rate-limit error mid-demo is worse than the load it would have prevented." Today a 429 does exactly that.
- **Observed, not assumed** (A/B'd in the browser against an intercepted 429): the old code shows *no toast at all* — silent — and Inertia covers the screen with a modal reading "All Inertia requests must receive a valid Inertia response, however a plain JSON response was received. {"message":"Too Many Attempts."}". There is **no navigation**; an earlier draft of this spec wrongly said "bounces to an error page". It's a modal overlay, and the content is a framework diagnostic — worse for a live demo than a wrong page would be.
- Verifying via the `demo.reset_enabled` flag doesn't work: laravel-vite-plugin watches `.env` and restarts on change, so the page reloads and the button correctly disappears before it can be clicked. Intercept the response instead.
- The reset button's in-flight state is already correct (`resetting` + `onFinish`); only the error handling is wrong. Don't rewrite what works.
- Reset is destructive and slow (~2.3s of database work). Unlike dismiss, a failed reset may have *partially* run, so the copy shouldn't promise nothing happened — keep it to "could not be reset."
- The three client→server actions are merge (`lib/merge.ts`), dismiss, and reset — a full sweep of `resources/js` found no `useForm`, no `<Form>`, no other `router` calls. This is the last of the three.
- **No Pest test** — same convention as the last two: the UI is prototype-grade, verified in the browser.

## Next up (separate branch)

`FieldPicker` radios stay live while a merge or dismiss is in flight — every other control on the screen disables. Needs a `disabled` prop plumbed from `merge-review.tsx`, gated on `committing || dismissing`. **Tidiness, not a bug:** `handleMerge` passes `picks` to `commitMerge()` at click time, so a radio clicked mid-flight cannot change what commits.

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

### Dismiss In-Flight State + Failure Toast
A failed dismiss said nothing and bounced to Inertia's error page; an in-flight one left every control live. The fix hangs on a docs detail: `onError` fires only for validation errors, so the obvious handler would have been dead code — `onHttpException`/`onNetworkError` returning `false` are what toast and hold the page. A `dismissing` state now relabels the button and gates Merge and the survivor toggle.
