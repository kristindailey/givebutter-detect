<?php

use App\Models\Contact;
use App\Models\DuplicateCandidate;
use App\Services\MergeService;
use Database\Seeders\DemoSeeder;

/**
 * The batch job end to end: generate candidates, score them, and repopulate the
 * queue. Asserts the queue contains exactly what the scorer says it should — the
 * hero pair in, the below-band pairs out.
 */
beforeEach(function () {
    $this->seed(DemoSeeder::class);
});

it('writes the Jennifer/Jen pair into the queue as a scored, pending candidate', function () {
    $this->artisan('detect:run')->assertSuccessful();

    $hero = DuplicateCandidate::query()
        ->where('contact_a_id', DemoSeeder::JENNIFER_ID)
        ->where('contact_b_id', DemoSeeder::JEN_ID)
        ->first();

    expect($hero)->not->toBeNull()
        ->and($hero->score)->toBe('94.00')
        ->and($hero->resolution)->toBe(DuplicateCandidate::RESOLUTION_PENDING)
        // signal_breakdown round-trips through jsonb as the array the queue renders.
        ->and($hero->signal_breakdown)->toBeArray()
        ->and(collect($hero->signal_breakdown)->pluck('signal'))
        ->toContain('name', 'email', 'phone', 'address', 'household');
});

it('never surfaces a below-band pair — parent/child stays out of the queue', function () {
    $this->artisan('detect:run')->assertSuccessful();

    $parentChild = DuplicateCandidate::query()
        ->where('contact_a_id', DemoSeeder::PARENT_ID)
        ->where('contact_b_id', DemoSeeder::CHILD_ID)
        ->exists();

    // ~35 is below the Review band; the dampened email + dob conflict keep it out.
    expect($parentChild)->toBeFalse()
        ->and(DuplicateCandidate::where('score', '<', 60)->exists())->toBeFalse();
});

it('is idempotent — a rerun upserts rather than duplicating', function () {
    $this->artisan('detect:run')->assertSuccessful();
    $firstCount = DuplicateCandidate::count();

    $this->artisan('detect:run')->assertSuccessful();

    expect(DuplicateCandidate::count())->toBe($firstCount)
        ->and($firstCount)->toBeGreaterThan(0);
});

/**
 * The rerun guarantees. `detect:run` means "rescore the current data without
 * undoing decisions already made" — resetting to zero is `seed:demo --detect`, a
 * different verb. Without these, a scheduled or repeated `detect:run` resurrects
 * a merged pair as pending, pointing at a contact the merge archived, which the
 * merge guard then rejects: a queue row nobody can act on.
 */
it('does not resurrect a merged pair — the archived loser stops generating candidates', function () {
    $this->artisan('detect:run')->assertSuccessful();

    $hero = DuplicateCandidate::query()
        ->where('contact_a_id', DemoSeeder::JENNIFER_ID)
        ->where('contact_b_id', DemoSeeder::JEN_ID)
        ->firstOrFail();

    $jennifer = Contact::findOrFail(DemoSeeder::JENNIFER_ID);
    $jen = Contact::findOrFail(DemoSeeder::JEN_ID);

    app(MergeService::class)->project($jennifer, $jen, commit: true);
    $hero->markMerged();

    $this->artisan('detect:run')->assertSuccessful();

    $backInQueue = DuplicateCandidate::pending()
        ->where('contact_a_id', DemoSeeder::JENNIFER_ID)
        ->where('contact_b_id', DemoSeeder::JEN_ID)
        ->exists();

    expect($hero->refresh()->resolution)->toBe(DuplicateCandidate::RESOLUTION_MERGED)
        ->and($backInQueue)->toBeFalse();
});

it('keeps a dismissal — the labeled negative survives a rerun', function () {
    $this->artisan('detect:run')->assertSuccessful();

    $candidate = DuplicateCandidate::pending()->firstOrFail();
    $candidate->markDismissed();

    $this->artisan('detect:run')->assertSuccessful();

    expect($candidate->refresh()->resolution)->toBe(DuplicateCandidate::RESOLUTION_DISMISSED);
});

it('prunes a pending pair it no longer detects', function () {
    $this->artisan('detect:run')->assertSuccessful();

    $candidate = DuplicateCandidate::pending()->firstOrFail();

    // Archiving one side removes the pair from candidate generation entirely.
    Contact::findOrFail($candidate->contact_b_id)->archive();

    $this->artisan('detect:run')->assertSuccessful();

    expect(DuplicateCandidate::find($candidate->id))->toBeNull();
});

it('refreshes the score of a pair that is already in the queue', function () {
    $this->artisan('detect:run')->assertSuccessful();

    $hero = DuplicateCandidate::query()
        ->where('contact_a_id', DemoSeeder::JENNIFER_ID)
        ->where('contact_b_id', DemoSeeder::JEN_ID)
        ->firstOrFail();

    $hero->forceFill(['score' => '1.00'])->save();

    $this->artisan('detect:run')->assertSuccessful();

    expect($hero->refresh()->score)->toBe('94.00');
});
