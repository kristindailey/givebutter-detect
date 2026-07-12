<?php

use App\Models\Contact;
use App\Models\DuplicateCandidate;
use Database\Seeders\DemoSeeder;

/**
 * The guards that live in the API layer rather than in MergeService: the
 * undetected-pair 404, the already-resolved 409 (a stale queue submit must not
 * re-merge), the archived-contact rejection, and the server-side picks whitelist
 * (the client is never trusted to name fields or send a bogus choice).
 * MergeService owns the money-math and is tested separately; these pin only the
 * thin request/controller layer above it.
 */
beforeEach(function () {
    $this->seed(DemoSeeder::class);
});

/** A precomputed candidate for the Jennifer/Jen hero pair, in canonical order. */
function heroCandidate(): DuplicateCandidate
{
    return DuplicateCandidate::create([
        'contact_a_id' => DemoSeeder::JENNIFER_ID,
        'contact_b_id' => DemoSeeder::JEN_ID,
        'score' => '94.00',
        'signal_breakdown' => [],
        'detected_at' => now(),
    ]);
}

it('returns 404 for a pair the detector never flagged and merges nothing', function () {
    // No candidate row for this pair. Merge is the resolution of a *detected*
    // duplicate — never a general-purpose "fuse any two contacts" endpoint — so a
    // pair that was never scored or queued cannot be committed, even though both
    // contacts exist and are individuals.
    $this->postJson('/api/contacts/merge', [
        'survivor_id' => DemoSeeder::JENNIFER_ID,
        'loser_id' => DemoSeeder::JEN_ID,
    ])->assertStatus(404);

    expect(Contact::withArchived()->find(DemoSeeder::JEN_ID)->isArchived())->toBeFalse();
});

it('rejects an archived contact on either side of the merge', function () {
    // Jen is already the loser of a previous merge. Candidates are strictly
    // pairwise, so a *pending* row can still name her — the 404/409 guards would
    // both wave this through, which is exactly why the archived check has to sit
    // in validation instead.
    heroCandidate();
    Contact::withArchived()->find(DemoSeeder::JEN_ID)->archive();

    // `message` carries the first validation error, and `merge.ts` toasts it
    // verbatim — so this copy is a contract with the client, not a label. A stale
    // tab has to be told the contact was merged away, not that an "id is invalid",
    // which is why the archived check is its own rule rather than a clause on
    // `exists` (one message per rule *name*, so a second `exists` would collide).
    $this->postJson('/api/contacts/merge', [
        'survivor_id' => DemoSeeder::JENNIFER_ID,
        'loser_id' => DemoSeeder::JEN_ID,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('loser_id')
        ->assertJsonPath('message', 'The contact you chose to merge in was already merged away. Reload the queue and try again.');

    // The dangerous direction: an archived survivor would re-point the loser's
    // transactions onto a soft-deleted record, hiding that giving history under
    // ArchivedScope.
    $this->postJson('/api/contacts/merge', [
        'survivor_id' => DemoSeeder::JEN_ID,
        'loser_id' => DemoSeeder::JENNIFER_ID,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('survivor_id')
        ->assertJsonPath('message', 'The contact you chose to keep was already merged away. Reload the queue and try again.');

    expect(Contact::withArchived()->find(DemoSeeder::JENNIFER_ID)->isArchived())->toBeFalse();
});

it('rejects a contact that is not mergeable at all', function () {
    // The other half of the copy contract. Unreachable from the UI — contacts only
    // arrive by seeder and the seed has no companies — so this is a shouldn't-
    // happen message, but `merge.ts` still toasts it verbatim, so it stays pinned.
    $this->postJson('/api/contacts/merge', [
        'survivor_id' => 999_999,
        'loser_id' => DemoSeeder::JEN_ID,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('survivor_id')
        ->assertJsonPath('message', 'The contact you chose to keep is not a mergeable contact.');

    expect(Contact::withArchived()->find(DemoSeeder::JEN_ID)->isArchived())->toBeFalse();
});

it('returns 409 for an already-resolved pair and merges nothing', function () {
    heroCandidate()->markMerged();

    $this->postJson('/api/contacts/merge', [
        'survivor_id' => DemoSeeder::JENNIFER_ID,
        'loser_id' => DemoSeeder::JEN_ID,
    ])->assertStatus(409);

    // The guard short-circuits before the service runs — the loser is untouched.
    expect(Contact::withArchived()->find(DemoSeeder::JEN_ID)->isArchived())->toBeFalse();
});

it('guards the picks map: rejects a bogus choice, drops unknown fields', function () {
    // A bogus choice for a real conflicting field is rejected outright, and with
    // validation failing before the controller body, nothing is merged.
    $this->postJson('/api/contacts/merge', [
        'survivor_id' => DemoSeeder::JENNIFER_ID,
        'loser_id' => DemoSeeder::JEN_ID,
        'picks' => ['first_name' => 'neither'],
    ])->assertStatus(422)->assertJsonValidationErrorFor('picks.first_name');

    expect(Contact::withArchived()->find(DemoSeeder::JEN_ID)->isArchived())->toBeFalse();

    // An unknown pick field is stripped before validation, so it can never steer
    // the merge — the request still succeeds and the merge commits normally.
    heroCandidate();

    $this->postJson('/api/contacts/merge', [
        'survivor_id' => DemoSeeder::JENNIFER_ID,
        'loser_id' => DemoSeeder::JEN_ID,
        'picks' => ['unknown_field' => 'wat'],
    ])->assertOk();

    expect(Contact::withArchived()->find(DemoSeeder::JEN_ID)->isArchived())->toBeTrue();
});
