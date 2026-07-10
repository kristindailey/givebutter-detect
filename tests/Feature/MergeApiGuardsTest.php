<?php

use App\Models\Contact;
use App\Models\DuplicateCandidate;
use Database\Seeders\DemoSeeder;

/**
 * The two guards that live in the API layer rather than in MergeService: the
 * already-resolved 409 (a stale queue submit must not re-merge) and the
 * server-side picks whitelist (the client is never trusted to name fields or send
 * a bogus choice). MergeService owns the money-math and is tested separately;
 * these pin only the thin request/controller layer above it.
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
