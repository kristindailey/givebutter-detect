# Givebutter Detect

A proactive data-trust layer for agentic CRM. Detect is a multi-signal weighted duplicate matcher with a merge preview that shows how giving history recomputes before anything commits.

One-liner: watch `contact_since` correct itself in real time when you merge Jennifer + Jen.

## The pitch: Detect, Score, Gate

Agents that act on records need a higher data-trust bar than a CRM built for humans. This prototype builds the first pillar.

- **Detect** (this repo): smarter duplicate detection plus a safe, reviewable merge.
- **Score** (deck only): a per-contact Trust Score.
- **Gate** (deck only): an agent pre-flight check before mutations.

Givebutter's public API can create, update, archive, restore, and household-link contacts, but has no merge endpoint. Merge is the destructive, high-trust operation a trust layer should own. This prototype builds the matcher and the review gate that sit on top of it.

## The two hero cases

The demo proves the prototype beats a naive exact-match rule in both directions.

1. **Jennifer / Jen** (naive rule misses, we catch). Same person, different first name and different emails. Matched via fuzzy preferred-name, shared household, and `dob` agreement. Scores ~94, merges, and drags `contact_since` backward.
2. **Parent / child** (naive rule over-merges, we do not). Different people sharing a household email, surname, and address. The household modifier dampens the shared-email signal and a conflicting `dob` pushes them apart. Scores ~35, never surfaced.

## How it works

- **Blocking, not O(n squared).** Candidate pairs are generated only from cheap shared keys (exact email/phone, trigram name/address, same household), backed by `pg_trgm` GIN indexes.
- **Weighted scoring.** Each pair gets a 0-100 confidence score with a per-signal `signal_breakdown` so the UI shows why it fired. Weights are hand-tuned for the demo; in production they would be learned from confirmed-merge history.
- **Household modifier.** Shared household is an asymmetric context modifier, not an additive signal: it dampens a shared email inside a family, boosts on matching identity markers, and pushes apart on a `dob` conflict.
- **Safe merge.** One `MergeService::project()` backs both the dry-run preview and the commit. On commit, inside one DB transaction, the loser is soft-deleted, its transactions re-point to the survivor, and three derived fields recompute from the source-of-truth transactions: `total_contributions`, `contact_since`, `last_donation_amount`.

## Tech stack

| Layer | Choice |
| ----- | ------ |
| App shell | Laravel + Inertia 2 (shared session, no CORS) |
| Frontend | TypeScript, React 19, Vite, Tailwind v4, shadcn/ui |
| Backend | PHP 8.4+, Laravel |
| Database | PostgreSQL with the `pg_trgm` extension |
| Tests | Pest / PHPUnit |

Auth is stubbed to an auto-logged-in seeded demo admin.

## Getting started

Requires PHP 8.4+, Node, and PostgreSQL with `pg_trgm` available.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed
php artisan detect:run
composer run dev
```

Seeding lands the contacts and hero cases; `detect:run` scores them into the queue (it is a separate step, so the queue is empty until it runs). `composer run dev` then runs the app server, queue, and Vite together. Open the app and go to `/duplicates`.

## Re-running the demo

A merge is permanent: the loser is archived and its transactions re-point to the survivor. To put the hero case back, `php artisan seed:demo --detect` resets the dataset and rescores the queue in one command.

**On the deployed demo,** the same reset runs on a schedule **every 10 minutes** and the top bar carries a "Reset demo" button.

The two commands mean deliberately different things. `seed:demo --detect` is *back to zero*. `detect:run` is *rescore the current data*. It refreshes scores and prunes pairs that no longer fire, but a pair you already merged or dismissed keeps that resolution because a dismissal is a labeled negative worth keeping.

## Commands

| Command | Purpose |
| ------- | ------- |
| `composer run dev` | App server, queue, and Vite together |
| `php artisan detect:run` | Rescore candidate pairs into `duplicate_candidates`, preserving resolutions |
| `php artisan seed:demo` | Reset the curated ~2k demo dataset and hero cases |
| `php artisan seed:demo --detect` | The same reset plus a rescore: the full demo reset in one command |
| `php artisan test --compact` | Run the test suite |
| `composer ci:check` | The full CI gate: build, lint, format, types, Pint, PHPStan, Pest |

## Routes

Read screens ride Inertia props. Only the two merge actions are JSON API routes because that is where the API design is the artifact: a dry-run GET and a committing POST sharing one projection.

| Route | Purpose |
| ----- | ------- |
| `GET /duplicates` | Review Queue (ranked pending pairs) |
| `GET /duplicates/{candidate}` | Merge Review (diff, picker, before/after) |
| `GET /api/contacts/merge-preview` | Dry run, commits nothing |
| `POST /api/contacts/merge` | Commit inside one DB transaction |

## Scope

Weekend-scoped. Each cut is a decision, not a gap.

- **In:** four primary signals (cross-field email, cross-field phone, fuzzy preferred-name, address trigram) plus the household modifier; safe merge with derived-field recompute.
- **Out (deliberate):** external-ID matching (the weekend cut line), custom-fields matching, segment recomputation, transitive clustering, rollback (soft-delete makes merges reversible by nature), and real auth.

## Testing

Tests cover the trust-critical logic only: the matcher and the money-math. The UI is prototype-grade.

```bash
php artisan test --compact
```

## Project structure

```
app/Services/Detection/   Normalizer, CandidateGenerator, PairScorer
app/Services/MergeService.php   shared preview/commit projection
app/Console/Commands/     detect:run, seed:demo
database/seeders/         DemoSeeder (~2k contacts + hero cases)
resources/js/pages/       review-queue.tsx, merge-review.tsx
tests/                    scoring + recompute on the two hero cases
```
