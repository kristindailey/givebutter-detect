<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\DuplicateCandidate;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Review Queue delivery + actions over Inertia (not JSON) — keeping the "exactly
 * two JSON endpoints" story intact. `index` is the ranked queue; `dismiss` is the
 * queue's other outcome (a labeled negative that, in production, would train the
 * scoring weights); `show` is the Merge Review page (stubbed here, built out by
 * the Merge Review feature).
 */
class DuplicateController extends Controller
{
    /**
     * The Review Queue: pending pairs at or above the review band, highest
     * confidence first, each carrying both contacts' display fields and the
     * precomputed `signal_breakdown` so the page renders the "why" chips without
     * recomputing anything. Reads as an Inertia prop, not JSON.
     */
    public function index(): Response
    {
        $reviewFloor = (float) config('detection.bands.review');

        $candidates = DuplicateCandidate::query()
            ->pending()
            ->where('score', '>=', $reviewFloor)
            ->with(['contactA', 'contactB'])
            ->get()
            ->map(fn (DuplicateCandidate $candidate): array => [
                'id' => $candidate->id,
                'score' => (float) $candidate->score,
                'band' => $this->band((float) $candidate->score),
                'contact_a' => $this->contactSummary($candidate->contactA),
                'contact_b' => $this->contactSummary($candidate->contactB),
                'signals' => $candidate->signal_breakdown,
            ])
            ->all();

        return Inertia::render('review-queue', [
            'candidates' => $candidates,
        ]);
    }

    /**
     * Merge Review page. Stubbed to a placeholder for now — the Merge Review
     * feature fills in the diff, picker, and before/after panel. Kept on this
     * controller (rather than a new one) per the Merge Review spec, which allows
     * folding the read into `DuplicateController`.
     */
    public function show(DuplicateCandidate $candidate): Response
    {
        return Inertia::render('merge-review', [
            'candidateId' => $candidate->id,
        ]);
    }

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

    /**
     * Which band a score routes into. `auto` (≥90) is agent-eligible; `review`
     * (60–89) is the human queue. The controller query already floors at the
     * review band, so `ignore` never reaches here.
     */
    private function band(float $score): string
    {
        return $score >= (float) config('detection.bands.auto') ? 'auto' : 'review';
    }

    /**
     * The display fields the queue row needs for one side of a pair.
     *
     * @return array{id: int, name: string, email: string|null}
     */
    private function contactSummary(Contact $contact): array
    {
        $name = trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));

        return [
            'id' => $contact->id,
            'name' => $name !== '' ? $name : 'Unnamed contact',
            'email' => $contact->primary_email,
        ];
    }
}
