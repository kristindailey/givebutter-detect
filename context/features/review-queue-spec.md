# Review Queue — Screen 1

## Overview

The ranked list of candidate duplicate pairs — the entry point. Each row shows both names, a 0–100 confidence score, a band badge, and compact per-signal "why" chips. Sorted highest-confidence first. Reads the precomputed `duplicate_candidates` table via an **Inertia prop** (no JSON endpoint). Rendered inside the shared Givebutter `AppShell`.

## Requirements

### Shared AppShell layout (built here, reused by Merge Review)

- Replicate Givebutter's chrome, per the real product screenshots (see [References](#references)):
  - **Black top bar** (`brand.black` `#1d1d1d`), full-width: Givebutter logo left (butter-yellow icon + wordmark, Nunito extra bold), center search box with `⌘K` affordance, right side help `?` / Chat / Tasks / user avatar. These are **decorative** — no real search or actions.
  - **Light left sidebar** (below the top bar): org switcher ("Change Collective ▾" + `+`), primary nav with icons (Home, Campaigns, Transactions, **Contacts**, Engage, Payouts, Settings), a "Givebutter Plus" section (Workflows, Custom Reports, Data Hygiene, Tasks), and a "Recent & Pinned" list. **Active item = light blue-gray rounded pill with blue text/icon** (Contacts in the screenshot).
  - **Light content area**: page title, tabs (`Individuals` / `Companies` / `Data Hygiene and Duplicates` with count badges), and the page body.
- Keep it **bounded** — the sidebar is largely **static/decorative**; only the path to this feature is active (Contacts → "Data Hygiene and Duplicates" tab). Don't wire real nav destinations. This honors "replicate the shell" without rebuilding their whole nav system.
- Brand tokens (yellow/black/blue, Nunito logo, Poppins headers, DM Sans body) from the Foundation theming.

### Queue page

- Controller passes ranked pairs as an Inertia prop: `duplicate_candidates WHERE resolution='pending' AND score >= 60`, ordered by `score DESC`, each with both contacts' display fields + `signal_breakdown`.
- Each **row**: `Contact A ↔ Contact B`, numeric score, a **band badge** (`≥90` → "agent-eligible"; `60–89` → "review"), and **signal chips** rendered from `signal_breakdown` (e.g. `name · household(+boost) · dob`; dampened/conflict modifiers shown).
- Row click → navigates to the Merge Review page for that candidate.
- **No pagination** — the curated set is small (hero + ~6–8 pairs).
- **Empty state** — when nothing is pending (after all merged/dismissed), show a clean "No duplicates to review" state.

## Files to Create

1. `resources/js/Layouts/AppShell.tsx` — black top bar + light sidebar, brand-themed (shared)
2. `resources/js/pages/ReviewQueue.tsx` — the ranked list + rows + chips. **Note:** the existing pages dir is lowercase `resources/js/pages/` (e.g. `health.tsx`) — follow that convention, not the capital `Pages/`.
3. `resources/js/components/` — `ScoreBadge`, `SignalChip` (small presentational components)
4. `app/Http/Controllers/DuplicateController.php` — **already exists** and hosts `dismiss()` from the Merge API spec; **add `index()`** here to return the Inertia prop.
5. `routes/web.php` — **add the queue route** (the `candidates.dismiss` POST route is already wired).

Use Wayfinder-generated route functions from `@/routes` / `@/actions` for the queue → Merge Review navigation and the row link — no hardcoded URLs.

## Key Gotchas

- Render chips **from `signal_breakdown`** — don't recompute anything client-side; the "why" is precomputed by `detect:run`.
- The parent/child pair (≈35) must **not** appear — it's below 60, filtered by the controller query.
- Sidebar is decorative: don't let it balloon into real routing/state.

## Testing (folded in)

No UI tests per the overview. Manual check: queue shows Jennifer/Jen at top (94, agent-eligible) with correct chips, curated review-band pairs below, parent/child absent.

## References

- `givebutter/project-overview.md` → Features (A. Review Queue), UI/UX (Screen 1), API Surface (Inertia prop)
- **AppShell chrome references** (`context/screenshots/`):
  - `contacts-view-with-data-hygiene.png` — the target screen: Contacts page, "Data Hygiene and Duplicates" tab, active Contacts nav pill
  - `home-view.png` — top bar + sidebar chrome, active-item styling
  - `campaign-view.png` — content-area tabs + table layout reference
- Depends on: `detection-phase-2-spec.md` (populated candidates), `foundation-spec.md` (theming)
- Links to: `merge-review-spec.md`