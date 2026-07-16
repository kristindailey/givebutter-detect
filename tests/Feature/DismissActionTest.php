<?php

use App\Models\DuplicateCandidate;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Collection;

/**
 * "Not a duplicate" — the Review Queue's other outcome. The side effect (the pair
 * resolves to a labeled negative) is covered by DataLayerGuardsTest; these pin the
 * response, because where the action *lands* is the half that went unnoticed: the
 * dismissal committed correctly while leaving the reviewer on the resolved pair.
 */
beforeEach(function () {
    $this->seed(DemoSeeder::class);
});

/** A precomputed candidate for the Jennifer/Jen hero pair, in canonical order. */
function pendingCandidate(): DuplicateCandidate
{
    return DuplicateCandidate::create([
        'contact_a_id' => DemoSeeder::JENNIFER_ID,
        'contact_b_id' => DemoSeeder::JEN_ID,
        'score' => '94.00',
        'signal_breakdown' => [],
        'detected_at' => now(),
    ]);
}

/**
 * The pair ids the Review Queue actually renders, read off the Inertia prop.
 *
 * @return Collection<int, int>
 */
function queueCandidateIds(): Collection
{
    $page = test()->get(route('duplicates.index'))->assertOk()->inertiaPage();

    return collect($page['props']['candidates'])->pluck('id');
}

it('redirects to the queue, not back to the pair just dismissed', function () {
    $candidate = pendingCandidate();

    // The referer is the Merge Review page — the only caller, and the reason
    // `back()` was wrong here: it returned the reviewer to the pair they had just
    // resolved. Setting it explicitly is what makes this a regression test rather
    // than a restatement of the redirect.
    $this->from("/duplicates/{$candidate->id}")
        ->post("/candidates/{$candidate->id}/dismiss")
        ->assertRedirect(route('duplicates.index'));

    expect($candidate->refresh()->resolution)->toBe(DuplicateCandidate::RESOLUTION_DISMISSED);
});

it('drops the dismissed pair from the queue it lands on', function () {
    $candidate = pendingCandidate();

    // Pinned present first: the queue starts with only this pair on it, so an
    // absence check alone would pass against an empty queue — including one that a
    // broken dismiss had emptied.
    expect(queueCandidateIds())->toContain($candidate->id);

    $this->post("/candidates/{$candidate->id}/dismiss");

    // The round trip the reviewer actually sees: the queue renders, and the pair
    // they just dismissed is gone from it.
    expect(queueCandidateIds())->not->toContain($candidate->id);
});

it('redirects to the queue even when the pair was already resolved', function () {
    // A stale tab dismissing a pair another tab already merged. The guard leaves
    // the merge outcome intact, but the reviewer still belongs at the queue —
    // stranding them on a resolved pair is the same dead end, minus the write.
    $candidate = pendingCandidate();
    $candidate->markMerged();

    $this->from("/duplicates/{$candidate->id}")
        ->post("/candidates/{$candidate->id}/dismiss")
        ->assertRedirect(route('duplicates.index'));

    expect($candidate->refresh()->resolution)->toBe(DuplicateCandidate::RESOLUTION_MERGED);
});
