# Detection Phase 2 — Scoring, Household Modifier & detect:run

## Overview

The scoring half of the matcher and the pitch centerpiece: a weighted per-signal scorer with the **asymmetric household modifier**, producing a 0–100 confidence per candidate pair plus a `signal_breakdown` explaining *why*. `detect:run` scores every pair from Phase 1's `CandidateGenerator` and (re)populates `duplicate_candidates`. Getting **both** hero cases right — catch Jennifer/Jen, refuse parent/child — is the whole demo.

## Requirements

### PairScorer — weighted signal scoring

- Weighted sum of per-signal agreement scores. **Starting weights** (hand-tuned, tune to hit the acceptance targets below):

| Signal | Weight (start) | Agreement basis |
| ------ | -------------- | --------------- |
| Email | 30 | any-to-any across `emails[]` (normalized) |
| Phone | 25 | any-to-any across `phones[]` (last-10 digits) |
| Name | 25 | **max** over { exact `name_key`, nickname-expanded match (`config/nicknames.php`), trigram similarity } |
| Address | 20 | trigram over `address_key` |
| Household | **modifier, not additive** | see below |

- Output: **0–100** confidence per pair.
- **Acceptance targets (hard):** Jennifer/Jen ≈ **94**; parent/child ≈ **35**. The parent/child assertion must be low *because of* the modifier + `dob` conflict, not incidentally.

### The household modifier (asymmetric — the centerpiece)

| Condition | Effect |
| --------- | ------ |
| Shared household **+ shared email** | **Dampen** the email signal's weight (families share inboxes → weak evidence) |
| Shared household **+ strong name agreement + `dob` agreement** | **Boost** confidence (same household + same identity markers → likely same person) |
| Shared household, **conflicting `dob`** | Push **toward "different people"** even if email matches |

`dob` is the zero-cost disambiguator that separates parent from child. This single rule wins both hero cases where the naive rule fails one in each direction.

### signal_breakdown JSON (exact shape the UI + API read)

```json
[
  {"signal": "name",    "contribution": 25, "matched": ["Jen", "Jennifer"], "via": "nickname"},
  {"signal": "email",   "contribution": 0,  "matched": [], "note": "dampened: shared household"},
  {"signal": "phone",   "contribution": 0,  "matched": []},
  {"signal": "address", "contribution": 20, "matched": ["123 Main St, …"]},
  {"signal": "household","modifier": "+boost", "reason": "dob agreement"}
]
```

Each additive signal carries `signal`, `contribution`, `matched` (the values that matched); household carries `modifier` (`+boost` / `-dampen` / `-conflict`) + `reason`. The Review Queue renders chips from this; the merge API echoes it.

### Confidence bands (config)

| Band | Score | Behavior |
| ---- | ----- | -------- |
| Auto-merge | ≥ 90 | Enters queue flagged "high confidence — agent-eligible" |
| Review | 60–89 | Surfaced for human decision — this prototype's job |
| Ignore | < 60 | Not written to the queue / not surfaced |

### detect:run

`php artisan detect:run` → calls `CandidateGenerator->generate()`, scores each pair with `PairScorer`, writes `score` + `signal_breakdown` + `detected_at` to `duplicate_candidates` (truncate + repopulate). Ignore-band pairs are still computed but not surfaced (store or skip — keep it simple: only persist ≥ 60, but the parent/child ≈35 must be verifiable in a test).

## Files to Create

1. `config/detection.php` — `weights`, band thresholds (`auto` => 90, `review` => 60), modifier params
2. `app/Services/Detection/PairScorer.php` — weighted scoring + household modifier + breakdown assembly
3. `app/Console/Commands/DetectRun.php` — `php artisan detect:run`

## Key Gotchas

- Name agreement is `max`, not sum, of the three name methods — don't double-count exact + trigram.
- The modifier adjusts the **email signal's contribution** and a global confidence nudge — keep dampen/boost/conflict as distinct, testable branches.
- Persisting only ≥60 is fine for the queue, but the parent/child test needs the raw score — expose scoring as a pure function (`PairScorer->score($a,$b): result`) independent of the persistence filter so the test can assert ≈35.

## Testing (folded in — the trust-critical half)

Pest test against the demo seed:
- Jennifer/Jen scores in the flagged-high band (≈94).
- parent/child scores low (≈35) **and** the assertion verifies it's low *because of* the household modifier + `dob` conflict (dampened email + conflict branch fired), not incidentally.
- (From Phase 1) the Jennifer/Jen pair is present in candidate generation.

## References

- `givebutter/project-overview.md` → Detection Algorithm (scoring, household modifier, bands), Testing
- Depends on: `detection-phase-1-spec.md`, `data-layer-spec.md`, `seed-demo-spec.md`
- Feeds: Review Queue, Merge API