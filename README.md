# Givebutter Detect

A proactive data-trust layer for agentic CRM. Detect is a multi-signal weighted duplicate matcher with a merge preview that shows how giving history recomputes before anything commits.

🔗 [Live demo:](https://givebutter-detect-production-klgyoq.laravel.cloud/) Merge Jennifer + Jen and watch `contact_since` correct itself.

https://github.com/user-attachments/assets/7c2e6dd5-cecc-4fd4-ba12-36721fd48be6

## The pitch: Detect, Score, Gate

When agents act on CRM records, the bar for trusting that data goes way up. This prototype builds the first pillar.

- **Detect** (this repo): smarter duplicate detection plus a safe, reviewable merge.
- **Score** (deck only): a per-contact trust score.
- **Gate** (deck only): an agent pre-flight check before an action commits.

Givebutter's API can create, update, archive, restore, and household-link contacts, but it has no merge action. Actions are a pattern it already uses: `PATCH /v1/contacts/{contact}/restore`, `POST /v1/contacts/{contact}/tags/add`, `.../tags/remove`, `.../tags/sync`, non-CRUD operations that hang off the contact with the verb in the path. Merge belongs in that list. 

Merge is the destructive, high-trust operation a trust layer should own. This prototype builds the matcher and the review gate that sit on top of Givebutter's existing dedupe.

## The two hero cases

Givebutter matches contacts two different ways and the two disagree. Import dedupe needs a name match *plus* a matching primary email or phone, so it creates real duplicates instead of catching them. The duplicate scan ignores names and matches on primary email or phone alone, so it proposes merging different people. 

Neither reads secondary contact info. Both land in a review queue. So the prototype's edge isn't the review step, it's which pairs reach it and what the reviewer is told. The demo proves it in both directions.

1. **Jennifer / Jen** (naive rule misses, Detect catches). Same person, different first name and different emails. Matched via fuzzy preferred-name, shared household, and `dob` agreement. Scores ~94, merges, and drags `contact_since` backward.
2. **Parent / child** (naive rule flags them, Detect keeps them apart). Different people sharing a household email, surname, and address. The household modifier dampens the shared-email signal and a conflicting `dob` pushes them apart. Scores ~35, never surfaced.

## How it works

- **Blocking, not O(n squared).** Candidate pairs are generated only from cheap shared keys (exact email/phone, trigram name/address, same household), backed by `pg_trgm` GIN indexes.
- **Weighted scoring.** Each pair gets a 0-100 confidence score with a per-signal `signal_breakdown` so the UI shows why it fired. Weights are hand-tuned for the demo; in production they would be learned from confirmed-merge history.
- **Household modifier.** Shared household is an asymmetric context modifier, not an additive signal: it dampens a shared email inside a family, boosts on matching identity markers, and pushes apart on a `dob` conflict.
- **Safe merge.** One `MergeService::project()` backs both the dry-run preview and the commit. On commit, inside one DB transaction, the loser is soft-deleted, its transactions re-point to the survivor, and three derived fields recompute from the source-of-truth transactions: `total_contributions`, `contact_since`, `last_donation_amount`. Three things it does differently from the merge that ships today:
  - **Recomputes instead of trusting.** The real API accepts `contact_since` as a `PUT` body field, so a donor's tenure is currently whatever the last writer said it was.
  - **Picks per field, not per record.** Givebutter's merge selects one primary record wholesale. This one surfaces only the fields that actually conflict.
  - **Archives rather than destroys.** Givebutter's merge removes the secondary's name, custom fields, and its birthday when it differs from the primary, warning, "You won't be able to undo this action." Here the loser is soft-deleted, so the record itself survives. That's short of an undo. See [Scope](#scope).

## Tech stack

| Layer | Choice |
| ----- | ------ |
| App shell | Laravel + Inertia 3 (shared session, no CORS) |
| Frontend | TypeScript, React 19, Vite, Tailwind v4, shadcn/ui |
| Backend | PHP 8.4+, Laravel |
| Database | PostgreSQL with the `pg_trgm` extension |
| Tests | Pest / PHPUnit |

Auth is stubbed to an auto-logged-in seeded demo admin.

## Getting started

### Deployed demo

[Try the live demo.](https://givebutter-detect-production-klgyoq.laravel.cloud/)

### Running it locally

Requires PHP 8.4+, Node, and PostgreSQL with `pg_trgm` available.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed
php artisan detect:run
composer run dev
```

Seeding lands the contacts and hero cases. `detect:run` scores them into the queue (it is a separate step, so the queue is empty until it runs). `composer run dev` then runs the app server, queue, and Vite together. Open the app and go to `/duplicates`.

**No Postgres on hand?** `docker compose up -d` (after the `cp .env.example .env` above) brings up a Postgres 16 with the right credentials baked in. The `pg_trgm` extension is enabled by migration. If you already run Postgres on port 5432, either stop it first or set `DB_PORT` to a free port in `.env`.

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
- **Out (deliberate):** external-ID matching (the weekend cut line), custom-fields matching, segment recomputation, transitive clustering, undo (The loser is archived rather than destroyed, but the commit doesn't log what moved. A real un-merge needs that audit trail, not just a button.), and real auth.

## Testing

Tests cover the trust-critical logic only: the matcher and the money-math. The UI is prototype-grade.

```bash
php artisan test --compact
```

## Project structure

```
app/Services/Detection/        Normalizer, CandidateGenerator, PairScorer, TrigramSimilarity
app/Services/MergeService.php  shared preview/commit projection
app/Console/Commands/          detect:run, seed:demo
database/seeders/              DemoSeeder (~2k contacts + hero cases)
resources/js/pages/            review-queue.tsx, merge-review.tsx
tests/                         scoring + recompute on the two hero cases
```
