<?php

namespace App\Http\Controllers;

use App\Http\Requests\MergeRequest;
use App\Models\Contact;
use App\Models\DuplicateCandidate;
use App\Services\MergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two JSON merge actions — a dry-run `GET` and a committing `POST` — sharing
 * one `MergeService->project()` projection. Preview and commit differ only by the
 * `commit` flag, so what the reviewer sees in the before/after panel is exactly
 * what the commit writes. This thin controller validates and delegates; all the
 * trust-critical logic lives in the tested service.
 */
class MergeController extends Controller
{
    public function __construct(private MergeService $mergeService) {}

    /**
     * Dry run — projects the merge of `loser` into `survivor` and returns the
     * projection DTO, committing nothing. Powers the before/after panel.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'survivor' => ['required', 'integer'],
            'loser' => ['required', 'integer', 'different:survivor'],
        ]);

        $survivor = Contact::withArchived()->whereKey($validated['survivor'])->firstOrFail();
        $loser = Contact::withArchived()->whereKey($validated['loser'])->firstOrFail();

        return response()->json($this->mergeService->project($survivor, $loser));
    }

    /**
     * Commit — merges `loser` into `survivor` inside the service's DB transaction,
     * then marks the candidate `merged`. Three guards stand in front of the
     * service, and all three must hold for a merge to be safe:
     *
     * 1. `MergeRequest` (upstream, in validation) rejects an archived contact on
     *    either side — a merge must never re-point transactions onto a record a
     *    previous merge already soft-deleted.
     * 2. A pair the detector never flagged returns 404. Merge is only ever the
     *    resolution of a *detected* duplicate; it is not a general-purpose "fuse
     *    any two contacts" endpoint.
     * 3. An already-resolved pair returns 409 rather than double-merging a stale
     *    queue submit.
     */
    public function commit(MergeRequest $request): JsonResponse
    {
        // Left under `ArchivedScope` (unlike `preview`, which reads archived pairs
        // deliberately): `MergeRequest` has already rejected an archived contact on
        // either side, so there is nothing here for `withArchived()` to rescue.
        $survivor = Contact::query()->whereKey($request->integer('survivor_id'))->firstOrFail();
        $loser = Contact::query()->whereKey($request->integer('loser_id'))->firstOrFail();

        $candidate = $this->candidateFor($survivor, $loser);

        if ($candidate === null) {
            return response()->json(
                ['message' => 'No duplicate candidate exists for this pair.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        if ($candidate->resolution !== DuplicateCandidate::RESOLUTION_PENDING) {
            return response()->json(
                ['message' => 'This pair has already been resolved.'],
                Response::HTTP_CONFLICT,
            );
        }

        $projection = $this->mergeService->project($survivor, $loser, $request->picks(), commit: true);

        $candidate->markMerged();

        return response()->json($projection);
    }

    /**
     * The precomputed candidate for this pair, if one exists. Pairs are stored in
     * canonical order (`contact_a_id < contact_b_id`), independent of which side
     * the reviewer chose as survivor.
     */
    private function candidateFor(Contact $survivor, Contact $loser): ?DuplicateCandidate
    {
        return DuplicateCandidate::query()
            ->where('contact_a_id', min($survivor->id, $loser->id))
            ->where('contact_b_id', max($survivor->id, $loser->id))
            ->first();
    }
}
