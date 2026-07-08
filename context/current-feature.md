<!-- Living document tracking the feature currently being worked on -->

# Current Feature: Foundation & Scaffold

Stand up the whole stack end-to-end before any feature code: Laravel + Inertia + React 19 + Vite + Tailwind v4, PostgreSQL with `pg_trgm`, stubbed demo-admin auth, Givebutter brand theming, and a `/health` page that proves the wiring works.

## Status

In Progress — starter kit scaffolded and DB live; ~half the foundation goals remain (see checkboxes).

## Goals

- [x] Scaffold with the official Laravel **React** starter kit — **DONE**: Inertia 2 + React 19 + TS + Tailwind v4 + shadcn/ui (`components.json`) + Vite already in place
- [x] Strip starter-kit auth to a stubbed demo admin — **DONE (FULL CLEAN STRIP)**: disabled all Fortify features + views in `config/fortify.php`; deleted the 7 auth pages, 3 settings pages + `Settings/` controllers + `routes/settings.php`, `dashboard`/`welcome` pages, the app-shell/sidebar component cluster (nav-user, app-header, app-sidebar, etc.), the `create_passkeys_table` + `add_two_factor_columns_to_users_table` migrations, and the starter auth/settings/dashboard tests; stripped Fortify traits/contract/2FA columns from `User` + `UserFactory`; gutted `FortifyServiceProvider`; simplified `app.tsx` (no global layout); `/` now redirects to `/health`. Verified: types ✅ build ✅ Pint ✅ PHPStan ✅ Pest ✅.
- [x] PostgreSQL 16 via `docker-compose.yml` on `:5432` — **DONE**: added `docker-compose.yml` (Postgres 16, `trust` auth, healthcheck, named volume) that interpolates creds from `.env` with `.env.example` defaults so it coexists with the running local PG. Aligned `.env.example` (APP_NAME/APP_URL). Verified end-to-end on a temp port (5433): container healthy, `pg_trgm`+`unaccent` available, app `migrate:fresh` ran against it; local PG on `:5432` left untouched.
- [x] Enable `pg_trgm` via migration — **DONE**: `2026_07_06_220135_enable_postgres_extensions.php` enables `pg_trgm` **and** `unaccent`; has run (`migrate:status` all Ran), self-contained via `migrate:fresh`
- [ ] Wire Givebutter brand tokens into an `@theme` block — **PARTIAL**: `@theme` block exists in `resources/css/app.css` but only holds starter-kit tokens; **no `brand.*` colors**, and blade uses the starter `@fonts` directive with **no Google Fonts link** for Nunito/Poppins/DM Sans
- [ ] `AutoLoginDemoAdmin` middleware — **NOT DONE**: `app/Http/Middleware/` has only `HandleAppearance` + `HandleInertiaRequests`
- [ ] `/health` Inertia page (DB, `pg_trgm`, hydration, auto-login rows) — **NOT DONE**: no `HealthController`, no `Health.tsx`, no route
- [ ] One-command setup holds — **PARTIAL**: composer/npm/migrate work, but `db:seed` (`DatabaseSeeder`) creates a generic `Test User`, not the demo admin; needs the demo-admin seed once auth is stubbed

### Decisions locked
- **Postgres:** add `docker-compose.yml` (Postgres 16) for reviewer portability; local PG stays working.
- **Auth strip:** FULL CLEAN STRIP — remove auth pages, settings pages/controllers/routes, passkey + 2FA migrations, and User-model Fortify traits/columns. Leave only `User` + demo admin.
- **Extensions:** keep `unaccent` alongside `pg_trgm` (needed for normalization); migration name `enable_postgres_extensions` stays.

### Remaining work (net)
1. Full auth strip: disable all Fortify features; delete `auth/` pages, `settings/` pages + `Settings/` controllers + `routes/settings.php`, `dashboard`/`welcome`; delete passkey + 2FA migrations; strip Fortify traits/columns from `User`
2. Seed a **demo admin** in `DatabaseSeeder` (replace generic Test User)
3. `AutoLoginDemoAdmin` middleware + register in the web group
4. Add `brand.*` color tokens + `--font-logo/heading/body` to the `@theme` block; add Google Fonts `<link>` (Nunito 800 / Poppins 600 / DM Sans) to the blade layout
5. `HealthController` + `Health.tsx` + `/health` route (4 green/red rows)
6. Add `docker-compose.yml` (Postgres 16 on `:5432`) and align `.env` DB creds to the service

## Notes

### Files to create
1. `docker-compose.yml` — Postgres 16 service on `:5432`
2. `database/migrations/xxxx_enable_pg_trgm.php` — `CREATE EXTENSION IF NOT EXISTS pg_trgm`
3. `app/Http/Middleware/AutoLoginDemoAdmin.php` — resolves `Auth::user()` to seeded demo admin
4. `app/Http/Controllers/HealthController.php` — gathers stack checks, returns Inertia prop
5. `resources/js/pages/Health.tsx` — renders check results
6. `resources/css/app.css` — brand tokens in `@theme` block
7. Root Blade layout (`resources/views/app.blade.php`) — Google Fonts `<link>` for Nunito / Poppins / DM Sans

### Brand tokens (`@theme` block)
Colors: `brand.yellow` `#febf04`, `brand.black` `#1d1d1d`, `brand.blue` `#1430e1`, `brand.white` `#ffffff`, `brand.purple` `#6f57d1`, `brand.cream` `#fff3cc`.
Fonts: Logo = Nunito Extra Bold (800), Headers = Poppins Semi Bold (600), Body = DM Sans Normal.
In v4: colors as `--color-brand-yellow`, fonts as `--font-logo`, `--font-heading`, `--font-body`. Logo assets in `givebutter/brand-assets/` (`logo-1/2/3.png`).

### Key gotchas
- Keep only the `User` model + seeded admin; disable Fortify features, delete auth routes/pages.
- `pg_trgm` must be enabled **before** any migration creating a GIN trigram index — this migration runs first.
- Register `AutoLoginDemoAdmin` in the web middleware group so Inertia pages see the user.
- In production this sits behind Givebutter's org-scoped auth — call out in README, not in code.

### Testing
No automated tests for foundation — loading `/health` with all rows green **is** the manual verification.

### References
- `context/project-overview.md` → Tech Stack, UI/UX Guidelines, Authentication
- `givebutter/brand-assets/` — logos
- Laravel React starter kit: https://laravel.com/docs/starter-kits
- Tailwind v4 `@theme`: https://tailwindcss.com/docs/theme

## History
