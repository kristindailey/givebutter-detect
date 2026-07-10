<?php

use App\Models\Contact;
use App\Models\Transaction;
use App\Services\MergeService;
use Database\Seeders\DemoSeeder;

/**
 * The money-math: the trust-critical half of the merge. Against the demo seed's
 * stable hero IDs, this pins the recompute (refund exclusion, `contact_since`
 * correcting backward), that the preview writes nothing, that the commit
 * re-points and archives inside one transaction, and that what the user previews
 * is exactly what commits.
 */
beforeEach(function () {
    $this->seed(DemoSeeder::class);
    $this->service = app(MergeService::class);
});

/** Load a contact with the relations the merge reads, archived-inclusive. */
function loadForMerge(int $id): Contact
{
    return Contact::withArchived()
        ->with(['emails', 'phones', 'addresses', 'externalIds', 'tags'])
        ->findOrFail($id);
}

it('proposes the more-complete record as survivor, order-independent', function () {
    // Jennifer carries title + company where Jen does not, so she wins on
    // completeness — and donor tenure never enters the decision.
    expect($this->service->proposeSurvivor(
        loadForMerge(DemoSeeder::JENNIFER_ID),
        loadForMerge(DemoSeeder::JEN_ID),
    )->id)->toBe(DemoSeeder::JENNIFER_ID);

    expect($this->service->proposeSurvivor(
        loadForMerge(DemoSeeder::JEN_ID),
        loadForMerge(DemoSeeder::JENNIFER_ID),
    )->id)->toBe(DemoSeeder::JENNIFER_ID);
});

it('recomputes the three derived fields over the union, excluding the refund', function () {
    $projection = $this->service->project(
        loadForMerge(DemoSeeder::JENNIFER_ID),
        loadForMerge(DemoSeeder::JEN_ID),
        [],
        commit: false,
    );

    // contact_since drags backward to Jen's 2019 gift — the demo moment.
    expect($projection['derived']['contact_since'])->toBe(['before' => '2021-06-02', 'after' => '2019-03-14'])
        // 1200 + 500; Jen's refunded $250 is excluded, so 1700 not 1950.
        ->and($projection['derived']['total_contributions'])->toBe(['before' => '1200.00', 'after' => '1700.00'])
        // Jennifer's $50 is still the latest succeeded gift across the union.
        ->and($projection['derived']['last_donation_amount'])->toBe(['before' => '50.00', 'after' => '50.00']);
});

it('writes nothing on a preview (commit=false)', function () {
    $this->service->project(
        loadForMerge(DemoSeeder::JENNIFER_ID),
        loadForMerge(DemoSeeder::JEN_ID),
        [],
        commit: false,
    );

    expect(Contact::withArchived()->find(DemoSeeder::JEN_ID)->isArchived())->toBeFalse()
        // Jen keeps both her rows (the $500 and the refunded $250) — nothing re-pointed.
        ->and(Transaction::where('contact_id', DemoSeeder::JEN_ID)->count())->toBe(2)
        // The survivor's stored derived value is untouched.
        ->and(Contact::withArchived()->find(DemoSeeder::JENNIFER_ID)->contact_since->toDateString())->toBe('2021-06-02');
});

it('surfaces only conflicting scalars — identical dob is hidden', function () {
    $scalars = $this->service->project(
        loadForMerge(DemoSeeder::JENNIFER_ID),
        loadForMerge(DemoSeeder::JEN_ID),
        [],
        commit: false,
    )['scalars'];

    expect($scalars)->toHaveKey('first_name')
        // Both born 1985-04-12, so dob is not a decision.
        ->and($scalars)->not->toHaveKey('dob')
        ->and($scalars['first_name'])->toBe([
            'survivor' => 'Jennifer',
            'loser' => 'Jen',
            'chosen' => 'Jennifer',
            'conflict' => true,
        ]);
});

it('gap-fills a survivor-empty scalar from the loser without a picker decision', function () {
    $projection = $this->service->project(
        loadForMerge(DemoSeeder::JENNIFER_ID),
        loadForMerge(DemoSeeder::JEN_ID),
        [],
        commit: false,
    );

    // Jennifer has no preferred_name; Jen's "Jen" fills the gap — surfaced as a
    // read-only fill (conflict: false), not a decision.
    expect($projection['scalars']['preferred_name'])->toBe([
        'survivor' => null,
        'loser' => 'Jen',
        'chosen' => 'Jen',
        'conflict' => false,
    ]);

    // ...and it carries over on commit rather than being dropped.
    $this->service->project(
        loadForMerge(DemoSeeder::JENNIFER_ID),
        loadForMerge(DemoSeeder::JEN_ID),
        [],
        commit: true,
    );

    expect(Contact::withArchived()->find(DemoSeeder::JENNIFER_ID)->preferred_name)->toBe('Jen');
});

it('unions array fields with dedupe — four tags collapse to three', function () {
    $arrays = $this->service->project(
        loadForMerge(DemoSeeder::JENNIFER_ID),
        loadForMerge(DemoSeeder::JEN_ID),
        [],
        commit: false,
    )['arrays'];

    // board-prospect is on both; the union keeps one copy.
    expect(collect($arrays['tags'])->pluck('name'))
        ->toHaveCount(3)
        ->toContain('major-donor', 'board-prospect', 'email-subscriber');
});

it('commits inside one transaction: re-points transactions, archives the loser, recomputes derived', function () {
    $this->service->project(
        loadForMerge(DemoSeeder::JENNIFER_ID),
        loadForMerge(DemoSeeder::JEN_ID),
        [],
        commit: true,
    );

    expect(Contact::withArchived()->find(DemoSeeder::JEN_ID)->isArchived())->toBeTrue()
        // Every loser transaction — the refunded one included — now points at Jennifer.
        ->and(Transaction::where('contact_id', DemoSeeder::JEN_ID)->count())->toBe(0);

    $survivor = Contact::withArchived()
        ->with(['emails', 'tags', 'externalIds'])
        ->find(DemoSeeder::JENNIFER_ID);

    expect($survivor->total_contributions)->toBe('1700.00')
        ->and($survivor->contact_since->toDateString())->toBe('2019-03-14')
        ->and($survivor->last_donation_amount)->toBe('50.00')
        // Loser's unique array rows moved onto the survivor.
        ->and($survivor->tags)->toHaveCount(3)
        ->and($survivor->emails->pluck('value'))->toContain('jensmith88@gmail.com')
        ->and($survivor->externalIds)->toHaveCount(2);
});

it('commits exactly what the preview projected', function () {
    $preview = $this->service->project(
        loadForMerge(DemoSeeder::JENNIFER_ID),
        loadForMerge(DemoSeeder::JEN_ID),
        [],
        commit: false,
    );

    $committed = $this->service->project(
        loadForMerge(DemoSeeder::JENNIFER_ID),
        loadForMerge(DemoSeeder::JEN_ID),
        [],
        commit: true,
    );

    // Same projection code on both paths, so the reviewed before/after is the truth.
    expect($committed['derived'])->toBe($preview['derived'])
        ->and($committed['scalars'])->toBe($preview['scalars'])
        ->and($committed['arrays'])->toBe($preview['arrays']);
});

it('applies a scalar pick to the survivor on commit', function () {
    $this->service->project(
        loadForMerge(DemoSeeder::JENNIFER_ID),
        loadForMerge(DemoSeeder::JEN_ID),
        ['first_name' => 'loser'],
        commit: true,
    );

    // The picker overrode the default; the survivor took the loser's first_name.
    expect(Contact::withArchived()->find(DemoSeeder::JENNIFER_ID)->first_name)->toBe('Jen');
});
