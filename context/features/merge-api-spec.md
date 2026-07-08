# Merge API — Preview (dry-run) & Commit

## Overview

The two JSON API routes — a dry-run `GET` and a committing `POST` sharing one `MergeService` projection — plus the Inertia dismiss action. This is the deliberate API surface: the overview spends its API budget **only** here, because a dry-run/commit pair sharing one projection is a design an async engineer reads directly from source. Everything else rides Inertia props.

## Requirements

### JSON API (exactly these two)

**`GET /api/contacts/merge-preview?survivor={id}&loser={id}`** — dry run
- Calls `MergeService->project(survivor, loser, picks?, commit=false)`.
- Returns the projection DTO (scalars with conflicts, unioned arrays, before/after derived). Commits nothing.
- Powers the before/after panel.

**`POST /api/contacts/merge`** — commit
- Request: `{ survivor_id, loser_id, picks: { field: 'survivor'|'loser' } }`.
- Validation: both contacts exist, `type = individual`, `survivor_id != loser_id`, `picks` reference real conflicting scalar fields.
- Calls `MergeService->project(..., commit=true)` inside the service's DB transaction; sets the candidate's `resolution='merged'`, `resolved_at=now()`.
- Returns the committed projection (so the client can toast + show final values).

### Inertia action (NOT a JSON route — preserves the "two endpoints" story)

**`POST /candidates/{candidate}/dismiss`** — "not a duplicate"
- Sets `resolution='dismissed'`, `resolved_at=now()` on the candidate (a labeled negative — the confirmed-history signal that would train scoring weights in production).
- Redirects back to the Review Queue (Inertia), pair no longer appears.

### Shared projection guarantee

`merge-preview` and `merge` call the **same** `MergeService->project()` (`commit=false` vs `true`) — preview == commit by construction.

## Files to Create

1. `app/Http/Controllers/MergeController.php` — `preview()` (GET) + `commit()` (POST)
2. `app/Http/Controllers/DuplicateController.php` — `dismiss()` Inertia action (may also host the queue read; see review-queue-spec)
3. `app/Http/Requests/MergeRequest.php` — validation for the commit POST
4. `routes/api.php` — the two JSON routes
5. `routes/web.php` — the dismiss action (+ Inertia page routes owned by the UI specs)

## Key Gotchas

- The merge POST must be idempotent-ish against a stale queue: if the candidate is already `resolved`, return a clean 409/conflict rather than double-merging.
- `picks` default to survivor values server-side — never trust the client to send every field.
- Keep the JSON routes free of the `archived` global-scope surprise: loading the loser for merge must see it.

## Testing (folded in)

No endpoint-integration tests per the overview ("no UI or endpoint-integration tests") — the projection/recompute correctness is covered by the MergeService test. The API layer is thin over the tested service.

## References

- `givebutter/project-overview.md` → API Surface (Partial API split, shared projection)
- Depends on: `merge-service-spec.md`, `data-layer-spec.md`
- Consumed by: `merge-review-spec.md`, `review-queue-spec.md`