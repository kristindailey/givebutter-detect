# Coding Standards

## TypeScript

- Strict mode enabled
- No `any` types - use proper typing or `unknown`
- Define interfaces for all props, Inertia page props, and data models
- Use type inference where obvious, explicit types where helpful

## React

- Functional components only (no class components)
- Use hooks for state and side effects
- Keep components focused - one job per component
- Extract reusable logic into custom hooks

## Inertia + Laravel

- Pages are rendered server-side via `Inertia::render()` and live in `resources/js/pages`
- Read-only screens (Review Queue, contact preview) receive data as **Inertia props** from the controller — no fetch layer
- Use real JSON API routes only for the two merge actions (`merge-preview` GET, `merge` POST) — that's where the API design is the artifact
- Use Wayfinder-generated functions from `@/actions` / `@/routes` for backend calls; no hardcoded URLs
- Keep business logic in Services (e.g. `MergeService`, `Detection\*`), not controllers

## Tailwind CSS v4

**CRITICAL**: We are using Tailwind CSS v4, which uses CSS-based configuration.

- **DO NOT** create `tailwind.config.ts` or `tailwind.config.js` files (those are for v3)
- All theme configuration must be done in CSS using the `@theme` directive in `resources/css/app.css`
- Use CSS custom properties for colors, spacing, etc.
- No JavaScript-based config allowed

Example v4 configuration:

```css
@import "tailwindcss";

@theme {
  --color-brand-yellow: #febf04;
}
```

## File Organization

- React pages: `resources/js/pages/[route].tsx`
- React components: `resources/js/components/ComponentName.tsx`
- Frontend lib/utils: `resources/js/lib/[utility].ts`
- Controllers: `app/Http/Controllers/[Name]Controller.php`
- Models: `app/Models/[Name].php`
- Services: `app/Services/[Domain]/[Name].php`
- Migrations / seeders: `database/migrations`, `database/seeders`

## Naming

- Components: PascalCase (`ItemCard.tsx`)
- PHP classes: PascalCase (`MergeService`, matches filename)
- TS functions: camelCase; PHP methods: camelCase (descriptive, e.g. `scoreCandidatePair`)
- Constants: SCREAMING_SNAKE_CASE
- Types/Interfaces: PascalCase (no prefix)

## Styling

- Tailwind CSS for all styling
- Use shadcn/ui components where applicable
- No inline styles
- Light-themed to Givebutter brand (see project-overview UI/UX)

## Database

- PostgreSQL; use Eloquent for all database operations
- Use Laravel migrations for schema changes; never edit the DB by hand
- Merge commits run inside a single DB transaction (re-point transactions + recompute derived fields atomically)
- Rely on `pg_trgm` GIN indexes for fuzzy blocking — keep candidate generation sub-quadratic

## Data Fetching

- Read screens fetch via Eloquent in the controller and pass Inertia props
- The two merge actions fetch via `fetch` to the JSON API
- Validate all inputs with Form Requests

## Error Handling

- Wrap the merge commit in a DB transaction so a failure rolls back cleanly
- Return structured JSON errors from the API routes
- Display user-friendly error messages via toast

## Testing

- Pest / PHPUnit for the trust-critical logic (scoring + money-math recompute), not UI
- Test files live in `tests/Feature` and `tests/Unit`
- Run tests: `php artisan test --compact` (filter with `--filter=name`) or `composer test` for the full gate
- Use model factories and states; seed hero cases (Jennifer/Jen, parent/child) for scoring assertions

## Code Quality

- No commented-out code unless specified
- No unused imports or variables
- Run `vendor/bin/pint --dirty` (PHP) and `npm run lint` (JS/TS) before finalizing
- Keep functions under 50 lines when possible