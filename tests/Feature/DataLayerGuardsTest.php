<?php

use App\Models\Contact;
use App\Models\DuplicateCandidate;
use App\Models\Transaction;

/**
 * The data layer's schema and relationships are exercised by the demo seeder,
 * which fails loudly when they're wrong. These tests cover what the seeder
 * can't: the guards that fail *silently* when broken.
 *
 * `makeContact()` lives in tests/Pest.php.
 */
it('never lets a mass-assignment set archived_at', function () {
    $contact = makeContact();

    // The merge commit mass-assigns from user picker input. Were `archived_at`
    // fillable, a crafted payload could retire the survivor instead of the loser.
    $contact->update(['first_name' => 'Jennifer', 'archived_at' => now()]);

    expect($contact->fresh()->first_name)->toBe('Jennifer')
        ->and($contact->fresh()->archived_at)->toBeNull();
});

it('never lets a mass-assignment set the derived giving fields', function () {
    $contact = makeContact();

    $contact->update([
        'total_contributions' => '999999.00',
        'contact_since' => '1999-01-01',
        'last_donation_amount' => '999999.00',
    ]);

    // Derived fields change only via recompute from transactions.
    $contact = $contact->fresh();

    expect($contact->total_contributions)->toBe('0.00')
        ->and($contact->contact_since)->toBeNull()
        ->and($contact->last_donation_amount)->toBeNull();
});

it('hides archived contacts by default and reveals them with withArchived', function () {
    $survivor = makeContact('Jennifer');
    $loser = makeContact('Jen');

    $loser->archive();

    expect(Contact::find($loser->id))->toBeNull()
        ->and(Contact::count())->toBe(1)
        ->and(Contact::withArchived()->count())->toBe(2)
        ->and(Contact::withArchived()->find($loser->id)->isArchived())->toBeTrue()
        ->and($survivor->fresh()->isArchived())->toBeFalse();
});

it('restores an archived contact, mirroring the API delete + restore', function () {
    $contact = makeContact();

    $contact->archive();
    expect(Contact::find($contact->id))->toBeNull();

    $contact->restore();
    expect(Contact::find($contact->id))->not->toBeNull()
        ->and($contact->fresh()->isArchived())->toBeFalse();
});

it('resolves both sides of a candidate after the loser is archived', function () {
    $survivor = makeContact('Jennifer');
    $loser = makeContact('Jen');

    $candidate = DuplicateCandidate::create([
        'contact_a_id' => min($survivor->id, $loser->id),
        'contact_b_id' => max($survivor->id, $loser->id),
        'score' => 94.0,
        'signal_breakdown' => ['name' => ['score' => 30, 'matched' => 'jen~jennifer']],
        'detected_at' => now(),
    ]);

    // A merged pair keeps its row, so both relations must outlive the archive.
    $loser->archive();
    $candidate = $candidate->fresh();

    expect($candidate->contactA)->not->toBeNull()
        ->and($candidate->contactB)->not->toBeNull()
        ->and($candidate->signal_breakdown['name']['matched'])->toBe('jen~jennifer');
});

it('queues only pending candidates, highest score first', function () {
    $ann = makeContact('Ann');
    $bob = makeContact('Bob');
    $cal = makeContact('Cal');

    $low = DuplicateCandidate::create([
        'contact_a_id' => $ann->id, 'contact_b_id' => $bob->id, 'score' => 62.0,
        'signal_breakdown' => [], 'detected_at' => now(),
    ]);
    $high = DuplicateCandidate::create([
        'contact_a_id' => $ann->id, 'contact_b_id' => $cal->id, 'score' => 94.0,
        'signal_breakdown' => [], 'detected_at' => now(),
    ]);

    // A dismissal is a labeled negative: kept on the table, gone from the queue.
    $dismissed = DuplicateCandidate::create([
        'contact_a_id' => $bob->id, 'contact_b_id' => $cal->id, 'score' => 88.0,
        'signal_breakdown' => [], 'detected_at' => now(),
    ]);
    $dismissed->markDismissed();

    expect(DuplicateCandidate::pending()->pluck('id')->all())->toBe([$high->id, $low->id])
        ->and(DuplicateCandidate::count())->toBe(3)
        ->and($dismissed->fresh()->resolved_at)->not->toBeNull();
});

it('excludes refunded and non-succeeded transactions from giving', function () {
    $contact = makeContact();

    $counts = fn (array $attributes) => Transaction::create([
        'contact_id' => $contact->id,
        'amount' => 50.00,
        'captured_at' => now(),
        ...$attributes,
    ])->countsTowardGiving();

    expect($counts(['id' => 'txn_ok', 'status' => 'succeeded']))->toBeTrue()
        ->and($counts(['id' => 'txn_refunded', 'status' => 'succeeded', 'refunded_at' => now()]))->toBeFalse()
        ->and($counts(['id' => 'txn_failed', 'status' => 'failed']))->toBeFalse();
});

it('never lets a mass-assignment set a candidate resolution', function () {
    $candidate = DuplicateCandidate::create([
        'contact_a_id' => makeContact('Ann')->id,
        'contact_b_id' => makeContact('Bob')->id,
        'score' => 94.0,
        'signal_breakdown' => [],
        'detected_at' => now(),
    ]);

    $candidate->update(['resolution' => DuplicateCandidate::RESOLUTION_MERGED]);

    // A pair leaves the queue only through markMerged() / markDismissed().
    expect($candidate->fresh()->resolution)->toBe(DuplicateCandidate::RESOLUTION_PENDING);

    $candidate->markMerged();
    expect($candidate->fresh()->resolution)->toBe(DuplicateCandidate::RESOLUTION_MERGED)
        ->and(DuplicateCandidate::pending()->count())->toBe(0);
});
