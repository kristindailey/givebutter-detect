<?php

use App\Models\DuplicateCandidate;
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

it('is idempotent — a rerun truncates and repopulates rather than duplicating', function () {
    $this->artisan('detect:run')->assertSuccessful();
    $firstCount = DuplicateCandidate::count();

    $this->artisan('detect:run')->assertSuccessful();

    expect(DuplicateCandidate::count())->toBe($firstCount)
        ->and($firstCount)->toBeGreaterThan(0);
});
