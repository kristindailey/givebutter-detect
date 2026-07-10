<?php

namespace App\Http\Controllers;

use App\Models\DuplicateCandidate;
use Illuminate\Http\RedirectResponse;

/**
 * Review Queue actions delivered over Inertia (not JSON) — keeping the "exactly
 * two JSON endpoints" story intact. Dismiss is the queue's other outcome: a
 * labeled negative that, in production, would train the scoring weights.
 */
class DuplicateController extends Controller
{
    /**
     * "Not a duplicate" — marks the pair `dismissed` and drops it from the queue.
     * Idempotent: a pair that's already resolved is left untouched, so a stale tab
     * can't overwrite a merge outcome. Redirects back to the Review Queue.
     */
    public function dismiss(DuplicateCandidate $candidate): RedirectResponse
    {
        if ($candidate->resolution === DuplicateCandidate::RESOLUTION_PENDING) {
            $candidate->markDismissed();
        }

        return back();
    }
}
