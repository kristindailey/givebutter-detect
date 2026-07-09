# Givebutter Detect

A proactive data-trust layer for agentic CRM. This prototype builds Detect: a multi-signal weighted duplicate matcher with a merge preview that shows how giving history recomputes before anything commits.

# Context Files

Read the following to get the full context of the project:

- @context/project-overview.md
- @context/coding-standards.md
- @context/ai-interaction.md
- @context/current-feature.md
- @AGENTS.md

## Commands

### Setup & dev
- `composer setup` — install deps, copy `.env`, generate key, migrate, build assets
- `composer run dev` (or `php artisan dev`) — run app server, queue, and Vite together
- `npm run dev` — Vite dev server only
- `npm run build` — production asset build (`npm run build:ssr` for SSR)

### Quality
- **`composer ci:check` — the gate.** Vite build, ESLint, Prettier, TypeScript, Pint, PHPStan, Pest. GitHub Actions runs exactly this, and nothing runs it for you (no pre-push hook). Run it before every commit.
- `composer test` — backend only: config clear, Pint check, PHPStan, Pest. An inner-loop command, **not** the gate — it never touches `resources/`.
- `php artisan test --compact` — run tests (filter with `--filter=name`)
- `composer lint` (`vendor/bin/pint --parallel`) — fix PHP formatting; `composer lint:check` to check only
- `composer types:check` (`phpstan analyse`) — static analysis
- `npm run lint` / `npm run format` — ESLint + Prettier on `resources/`; `lint:check` / `format:check` to check only
- `npm run types:check` — TypeScript check (`tsc --noEmit`)

### Domain (planned — see project-overview.md, not yet implemented)
- `php artisan detect:run` — batch-score candidate pairs into `duplicate_candidates`
- `php artisan seed:demo` — reset the curated ~2k demo dataset + hero cases
- `php artisan seed:bulk` — seed 100k synthetic contacts (stretch)

## Database

- Local **PostgreSQL** (`DB_CONNECTION=pgsql`, db `givebutter_detect`); requires the `pg_trgm` extension for trigram fuzzy matching.
- **Tests run on Postgres too**, against `givebutter_detect_testing` — never SQLite, since `pg_trgm` is the matcher. `Tests\TestCase` creates that database on first run; no manual setup.
- Prefer Laravel Boost's `database-query` (read-only queries) and `database-schema` (inspect tables) MCP tools over raw SQL in tinker.
- Never edit the DB by hand — use Laravel migrations for all schema changes.

**IMPORTANT:** Do not add Claude to any commit messages