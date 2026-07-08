# MergeService — Shared Preview/Commit Projection & Recompute

## Overview

The trust-critical core. One `MergeService->project(...)` method does both the dry-run preview and the real commit (`commit=false` vs `commit=true`), so **what the user previews is exactly what commits**. It proposes a survivor, resolves fields via three tiers (pick scalars / union arrays / recompute derived), and on commit re-points transactions and archives the loser inside one DB transaction. Owns the projection shape the Merge API and before/after panel consume.

## Requirements

### Survivor proposal (overridable)

Auto-propose survivor = the record that is **more complete**, tie-broken by recency, then giving:

```
survivor = argmax over ( count(non-null significant fields), then updated_at, then total_contributions )
```

The richer record is kept (Jennifer wins case 1). User can override with a clearly-marked toggle. **Donor tenure is NOT a survivor concern** — `contact_since` recomputes over the union regardless of who survives.

### Three-tier field resolution

1. **Scalars** — per-field picker, surfaced **only** where the two values actually conflict (identical values are hidden, not decisions). `picks` param carries the user's choices; default = survivor's value.
2. **Arrays** — auto-union with dedupe, read-only "kept both." Dedupe keys:

   | Array | Dedupe key |
   | ----- | ---------- |
   | emails | normalized value |
   | phones | last-10 digits |
   | addresses | `address_key` |
   | tags | value |
   | external_ids | (label, external_id) |

3. **Derived** — recomputed from the **post-repoint union** of both contacts' transactions, excluding refunded / non-succeeded:

   | Field | Rule |
   | ----- | ---- |
   | `total_contributions` | `SUM(amount) WHERE status='succeeded' AND refunded_at IS NULL` |
   | `contact_since` | `MIN(captured_at)` over succeeded rows |
   | `last_donation_amount` | `amount` of `MAX(captured_at)` among succeeded rows |

### Projection shape (owned here; API wraps it)

```
MergeService->project(survivor, loser, picks, commit) : {
  scalars:  { field: { survivor, loser, chosen, conflict: bool } },   // only conflicting fields
  arrays:   { emails:[...], phones:[...], addresses:[...], tags:[...], external_ids:[...] },
  derived:  { total_contributions:{before,after}, contact_since:{before,after}, last_donation_amount:{before,after} }
}
```

`before` = survivor's current derived value; `after` = recomputed over the union. Preview and commit return the same shape.

### Commit (commit=true) — one DB transaction

Inside a single `DB::transaction`:
1. Loser gets `archived_at = now()` (soft-delete, mirrors reversible DELETE+restore).
2. All loser transactions re-point `contact_id` → survivor.
3. Derived fields recompute on the survivor.
4. Survivor scalar fields update per `picks`; arrays update per union rules.

## Files to Create

1. `app/Services/MergeService.php` — `project()` (shared), survivor proposal, three-tier resolution, recompute, commit transaction

## Key Gotchas

- The recompute must run over the **post-repoint** union — either re-point first then aggregate, or aggregate over `WHERE contact_id IN (survivor, loser)` and assign to survivor. Be consistent; the money-math test pins this.
- Loading the loser must **bypass the `archived` global scope** if it were already archived (defensive), and the commit sets `archived_at` last.
- Preview must load the loser even though nothing is written — `commit=false` touches no rows.
- Refunded/non-succeeded rows are excluded from all three derived fields (the refund-exclusion is a seeded, tested case).

## Testing (folded in — the money-math)

Pest test against the demo seed:
- `contact_since = MIN(captured_at)` over the union → Jennifer/Jen corrects 2021-06-02 → 2019-03-14.
- refund exclusion applied → `total_contributions` = $1,700 not $1,700+refund.
- `last_donation_amount` = latest succeeded row.
- transactions are re-pointed to the survivor (loser has none after commit; loser `archived_at` set).
- preview (`commit=false`) writes nothing; commit result equals the preview's projected `after` values.

## References

- `givebutter/project-overview.md` → Safe merge, Derived-field reconciliation, recompute rules, Safe Commit
- Depends on: `data-layer-spec.md`, `seed-demo-spec.md`
- Consumed by: `merge-api-spec.md`, `merge-review-spec.md`