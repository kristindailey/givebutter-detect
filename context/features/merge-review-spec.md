# Merge Review — Screen 2

## Overview

The single-screen merge experience: side-by-side diff, per-field primary picker for conflicts only, auto-union array summary, and the **before/after panel** where the 3 derived fields recompute from transactions. This is the demo payoff — watch `contact_since` correct itself. Also hosts the two outcomes: **Merge** (commit) and **Not a duplicate** (dismiss). Rendered in the shared `AppShell`.

## Layout (from the overview mockup)

```
┌──────────────────────────────────────────────────────────────┐
│  Merge Review          Confidence 94  [ Survivor: Jennifer ▾]│
├───────────────────────────────┬──────────────────────────────┤
│  Jennifer Smith   (survivor)  │  Jen Smith   (soft-delete)   │
│  first_name   (•) Jennifer    │  ( ) Jen        ← conflict   │
│  emails       work@… + jen@…  →  union: both kept (read-only)│
│  phones       (555)…          →  union: both kept (read-only)│
├───────────────────────────────┴──────────────────────────────┤
│  BEFORE → AFTER (recomputed from transactions)               │
│  contact_since        2021-06-02  →  2019-03-14 ← corrected  │
│  total_contributions  $1,200.00   →  $1,700.00               │
│  last_donation_amount $50.00      →  $50.00                  │
│                    [ Not a duplicate ]   [ Cancel ] [ Merge ]│
└──────────────────────────────────────────────────────────────┘
```

## Requirements

### Data flow

- Page loads with **both full contact records** (arrays included) as an **Inertia prop** (for the diff + picker).
- Before/after panel fetches **`GET /api/contacts/merge-preview`** on mount, and **again only when the survivor toggle flips** (derived values depend on who survives, not on scalar picks).
- Scalar **picker choices are local state** until commit; they don't re-fetch.

### Diff + picker (scalars)

- Per-field **radio picker shown only for scalar fields that actually conflict** (identical values hidden — not decisions). Default selection = survivor's value.
- Survivor auto-proposed by `MergeService` (Jennifer wins case 1); header dropdown lets the user **override** the survivor, which re-fetches the preview swapped.

### Arrays (read-only)

- `emails`, `phones`, `addresses`, `tags`, `external_ids` render as an auto-union "both kept" summary from the projection's `arrays` — read-only, no picker.

### Before/After panel (the payoff)

- Renders the projection's `derived` before/after for `contact_since`, `total_contributions`, `last_donation_amount`.
- **Changed after-values render in brand blue** against the struck-through before-value.

### Outcomes

- **Merge** → `POST /api/contacts/merge` with `{survivor_id, loser_id, picks}`. High-trust: **no optimistic UI** — wait for the server, then toast success and redirect to the queue.
- **Not a duplicate** → `POST /candidates/{id}/dismiss` (Inertia), redirect to queue (labeled negative).
- **Cancel** → back to queue, no mutation.

## Files to Create

1. `resources/js/Pages/MergeReview.tsx` — page composition + fetch/commit logic
2. `resources/js/components/FieldPicker.tsx` — conflict-only scalar picker
3. `resources/js/components/ArrayUnionSummary.tsx` — read-only "both kept"
4. `resources/js/components/BeforeAfterPanel.tsx` — derived diff (changed after-values in brand blue)
5. `resources/js/components/SurvivorToggle.tsx` — header override dropdown
6. `app/Http/Controllers/ContactController.php` — Inertia prop with both full contact records (or fold into DuplicateController)
7. `routes/web.php` — the merge-review page route

## Key Gotchas

- Only **conflicting** scalars get a picker — compute conflicts from the projection's `scalars` (each carries `conflict: bool`); hide identical fields.
- Commit sends **picks**; the server still defaults unspecified fields to survivor — don't rely on the client sending all.
- Wait for the merge POST before toasting (no optimistic UI) — merge is destructive/high-trust.
- Case 2 (parent/child) never reaches this screen via the queue (below band); it's not a merge-review path.

## Testing (folded in)

No UI tests per the overview. Manual: open Jennifer/Jen → before/after shows `contact_since` correcting 2021-06-02 → 2019-03-14 (in brand blue), total $1,200 → $1,700; Merge toasts and the pair leaves the queue; re-run via `seed:demo`.

## References

- `givebutter/project-overview.md` → Features (B. Merge Review, C. Safe Commit), UI/UX (Screen 2), API Surface
- Depends on: `merge-service-spec.md`, `merge-api-spec.md`, `review-queue-spec.md` (AppShell), `foundation-spec.md`