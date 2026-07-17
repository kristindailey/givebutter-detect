# Foundation & Scaffold

## Overview

Stand up the whole stack end-to-end before any feature code: Laravel + Inertia + React 19 + Vite + Tailwind, PostgreSQL with `pg_trgm`, a stubbed demo-admin auth, Givebutter brand theming, and a single health-check page that proves the wiring works. This is the ~2–3 hr Saturday-morning setup; budget generously because the `pg_trgm` extension and Inertia/Vite wiring can fight you.

## Requirements

- Scaffold with the **official Laravel React starter kit** (run `laravel new`, select the **React** kit with **built-in auth — not WorkOS**) to get Inertia 3 + React 19 + TS + Tailwind v4 + shadcn/ui + Vite in one command. (Laravel Breeze is frozen as of Laravel 12 and is no longer the recommended path.)
- **Strip the starter-kit auth** down to a stubbed demo admin (see AutoLoginDemoAdmin below) — no login/register screens. Auth is driven by **Laravel Fortify**; disable its features in `config/fortify.php` and drop the shipped auth routes/pages.
- **PostgreSQL via Docker Compose** — a single `docker-compose.yml` running Postgres 16 on `:5432`. No Sail.
- Enable `pg_trgm` via a migration (`CREATE EXTENSION IF NOT EXISTS pg_trgm`), not a manual step, so `migrate:fresh` is self-contained.
- **Brand theming** — wire Givebutter brand tokens (colors + fonts) into an `@theme` block in `resources/css/app.css`. Tailwind v4 is **CSS-first — there is no `tailwind.config.ts`**. Fonts via Google Fonts CDN `<link>` in the root layout.
- **Health-check page** at `/health` (Inertia page) that verifies the full stack.
- One-command setup must hold: `composer install && npm install && php artisan migrate:fresh --seed && npm run dev`.

## Files to Create

1. `docker-compose.yml` — Postgres 16 service on `:5432`
2. `database/migrations/xxxx_enable_pg_trgm.php` — `CREATE EXTENSION IF NOT EXISTS pg_trgm`
3. `app/Http/Middleware/AutoLoginDemoAdmin.php` — resolves `Auth::user()` to the seeded demo admin on every request
4. `app/Http/Controllers/HealthController.php` — gathers stack checks, returns an Inertia prop
5. `resources/js/pages/Health.tsx` — renders the check results
6. `resources/css/app.css` — Givebutter brand tokens in an `@theme` block (see below)
7. Root Blade layout (`resources/views/app.blade.php`) — Google Fonts `<link>` for Nunito / Poppins / DM Sans

## Brand Tokens (into the `@theme` block in `resources/css/app.css`)

**Colors**

| Token | Hex |
| ----- | --- |
| `brand.yellow` | `#febf04` |
| `brand.black` | `#1d1d1d` |
| `brand.blue` | `#1430e1` |
| `brand.white` | `#ffffff` |
| `brand.purple` | `#6f57d1` |
| `brand.cream` | `#fff3cc` |

**Fonts** (Google Fonts CDN)

| Role | Font |
| ---- | ---- |
| Logo | Nunito Extra Bold (800) |
| Headers | Poppins Semi Bold (600) |
| Body | DM Sans Normal |

In Tailwind v4 these become CSS variables inside `@theme` — colors as `--color-brand-yellow: #febf04;` (usable as `bg-brand-yellow`, `text-brand-black`, etc.) and fonts as `--font-logo`, `--font-heading`, `--font-body`.

Logo assets live in `givebutter/brand-assets/` (`logo-1/2/3.png`).

## Health-Check Page

`GET /health` renders an Inertia page asserting:

- ✅ DB connection succeeds
- ✅ `pg_trgm` extension present (`SELECT * FROM pg_extension WHERE extname = 'pg_trgm'`)
- ✅ React/Vite hydration (page is interactive)
- ✅ demo admin auto-logged-in (`Auth::user()` resolves)

Each check renders as a labeled row (green/red). This page is the gate before feature work begins.

## AutoLoginDemoAdmin

Middleware that logs in the seeded demo admin on every request so `Auth::user()` always resolves. No login flow. In production this sits behind Givebutter's org-scoped auth — call that out in the README, not in code.

## Key Gotchas

- The starter kit ships Fortify session auth; we keep only the `User` model + seeded admin and disable Fortify features in `config/fortify.php`, then delete the auth routes/pages.
- `pg_trgm` must be enabled **before** any migration that creates a GIN trigram index (ordering matters) — this migration runs first.
- Register `AutoLoginDemoAdmin` in the web middleware group so Inertia pages see the user.

## Testing

No automated tests for foundation — the health-check page **is** the manual verification. Loading `/health` with all rows green confirms the stack.

## References

- `givebutter/project-overview.md` → Tech Stack, UI/UX Guidelines (brand tokens), Authentication
- `givebutter/brand-assets/` — logos
- Laravel starter kits (React): https://laravel.com/docs/starter-kits
- Tailwind v4 CSS-first config (`@theme`): https://tailwindcss.com/docs/theme