<?php

/**
 * Scoring weights, confidence bands, and household-modifier parameters for the
 * matcher (Detection phase 2).
 *
 * The weights and modifier magnitudes are **hand-tuned** so the two hero cases
 * land where the pitch needs them — Jennifer/Jen at 94 (flagged-high), parent/
 * child at ~35 (never surfaced). In production these would be *learned* from
 * Givebutter's confirmed-merge history (every past merge is a labeled pair);
 * here they are constants, tuned once against the seed, and honest about it.
 *
 * Reference arithmetic against the demo seed:
 *   Jennifer/Jen  = name 25 (nickname) + address 20 + boost 49            = 94
 *   parent/child  = email 14.4 (dampened ×0.4) + name ~7.9 (trigram 0.316)
 *                   + address 20 − conflict 8                             ≈ 34
 */
return [

    /**
     * Additive per-signal weights. A signal contributes `weight × agreement`,
     * where agreement is in [0, 1].
     *
     * Email and phone are exact cross-contact identifiers — the strongest identity
     * evidence — so they outweigh the fuzzy name/address signals. That ordering is
     * also what draws the queue's cut line: a pair needs a shared email or phone
     * (or household corroboration) to clear the Review band. A name + address match
     * alone tops out at 45 and stays out — the same ceiling that keeps the ~2k
     * noise contacts (which share no email/phone/household) out of the queue.
     */
    'weights' => [
        'email' => 36,
        'phone' => 36,
        'name' => 25,
        'address' => 20,
    ],

    /**
     * Score → band routing. A pair at or above `auto` is agent-eligible (still
     * queued here, flagged high); `review`..`auto` is the human queue; below
     * `review` is noise and is never written to `duplicate_candidates`.
     */
    'bands' => [
        'auto' => 90,
        'review' => 60,
    ],

    /**
     * The asymmetric household modifier — the centerpiece. Shared household
     * membership is double-edged, so it is never an additive signal:
     *
     * - `email_dampen_factor`: a shared inbox inside a household is weak evidence
     *   (families share one), so the email contribution is scaled down, not counted
     *   in full. Scaling to 0.4 is what keeps parent/child under the Review band.
     * - `boost`: shared household + a strong name agreement + agreeing `dob` is
     *   near-proof of same person; this lifts Jennifer/Jen into the flagged band.
     * - `conflict_penalty`: shared household with *conflicting* `dob` pushes toward
     *   "different people" even when the email matches — the parent/child rule.
     * - `strong_name_threshold`: the name agreement (in [0, 1]) that counts as
     *   "strong" for the boost — 0.85 admits exact + nickname matches but not a
     *   borderline trigram.
     */
    'modifier' => [
        'email_dampen_factor' => 0.4,
        'boost' => 49,
        'conflict_penalty' => 8,
        'strong_name_threshold' => 0.85,
    ],

    /**
     * Trigram agreement below this contributes nothing. Matches pg_trgm's default
     * `similarity_threshold`, so a name/address pair the blocking stage would not
     * have surfaced on its own adds no score noise here either.
     */
    'similarity_floor' => 0.3,
];
