<?php

use App\Models\Contact;
use App\Models\Transaction;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The Detection and MergeService tests will assert against this seed's stable
 * hero IDs, so the figures below are load-bearing: if DemoSeeder drifts, the
 * before/after panel silently stops proving anything.
 *
 * The suite runs on Postgres, so the trigram assertions below exercise the real
 * `pg_trgm` operator the blocking queries will use.
 */
beforeEach(function () {
    $this->seed(DemoSeeder::class);
});

it('seeds the hero contacts at their stable ids', function () {
    $jennifer = Contact::find(DemoSeeder::JENNIFER_ID);
    $jen = Contact::find(DemoSeeder::JEN_ID);

    expect($jennifer->first_name)->toBe('Jennifer')
        ->and($jen->first_name)->toBe('Jen')
        ->and($jen->preferred_name)->toBe('Jen')
        ->and($jennifer->last_name)->toBe($jen->last_name)
        ->and($jennifer->dob->toDateString())->toBe($jen->dob->toDateString());
});

it('gives Jennifer and Jen different emails and phones, so the naive rule misses them', function () {
    $jennifer = Contact::with(['emails', 'phones'])->find(DemoSeeder::JENNIFER_ID);
    $jen = Contact::with(['emails', 'phones'])->find(DemoSeeder::JEN_ID);

    $sharedEmails = $jennifer->emails->pluck('normalized_value')->intersect($jen->emails->pluck('normalized_value'));
    $sharedPhones = $jennifer->phones->pluck('normalized_value')->intersect($jen->phones->pluck('normalized_value'));

    expect($sharedEmails)->toBeEmpty()
        ->and($sharedPhones)->toBeEmpty();
});

it('puts Jennifer and Jen in one household — the block that generates the hero pair', function () {
    // Their emails and phones differ and trigram('jen smith','jennifer smith')
    // sits near the threshold. Without this shared household, candidate
    // generation drops the headline demo pair entirely.
    $jennifer = Contact::with('households')->find(DemoSeeder::JENNIFER_ID);
    $jen = Contact::with('households')->find(DemoSeeder::JEN_ID);

    expect($jennifer->households->pluck('id')->intersect($jen->households->pluck('id')))->toHaveCount(1);
});

it('seeds the hero derived fields to their pre-merge values', function () {
    $jennifer = Contact::find(DemoSeeder::JENNIFER_ID);

    // The "before" column of the before/after panel.
    expect($jennifer->total_contributions)->toBe('1200.00')
        ->and($jennifer->contact_since->toDateString())->toBe('2021-06-02')
        ->and($jennifer->last_donation_amount)->toBe('50.00');
});

it('excludes the refunded transaction from Jen\'s pre-merge giving', function () {
    $jen = Contact::find(DemoSeeder::JEN_ID);
    $refunded = Transaction::where('contact_id', DemoSeeder::JEN_ID)->whereNotNull('refunded_at')->first();

    // A $250 refund exists on the record but never reaches the derived total.
    expect($refunded)->not->toBeNull()
        ->and($refunded->amount)->toBe('250.00')
        ->and($refunded->countsTowardGiving())->toBeFalse()
        ->and($jen->total_contributions)->toBe('500.00');
});

it('recomputes the hero merge to the exact figures the demo promises', function () {
    // The payoff. Computed over the post-repoint union of both contacts'
    // transactions, excluding refunded rows — the rule MergeService implements.
    $succeeded = Transaction::query()
        ->whereIn('contact_id', [DemoSeeder::JENNIFER_ID, DemoSeeder::JEN_ID])
        ->where('status', Transaction::STATUS_SUCCEEDED)
        ->whereNull('refunded_at')
        ->orderBy('captured_at')
        ->get();

    expect((string) $succeeded->sum(fn (Transaction $t) => (float) $t->amount))->toBe('1700')
        ->and($succeeded->first()->captured_at->toDateString())->toBe('2019-03-14')
        ->and($succeeded->last()->amount)->toBe('50.00');
});

it('gives the hero pair overlapping tags, so the merge union has a dedupe to perform', function () {
    $jennifer = Contact::with('tags')->find(DemoSeeder::JENNIFER_ID);
    $jen = Contact::with('tags')->find(DemoSeeder::JEN_ID);

    $union = $jennifer->tags->pluck('name')->merge($jen->tags->pluck('name'))->unique()->values();

    // Four tags across the pair, one of them shared: the "kept both" summary must
    // render three, not four. Tags are never matched on and never diffed —
    // surviving a merge is the only thing they do.
    expect($jennifer->tags)->toHaveCount(2)
        ->and($jen->tags)->toHaveCount(2)
        ->and($union->all())->toBe(['major-donor', 'board-prospect', 'email-subscriber']);
});

it('mirrors an external id on each side of the hero pair', function () {
    // Mirrored, never matched — external-ID matching is the deliberate cut line.
    // Seeded so the union summary renders a value rather than an empty row.
    $jennifer = Contact::with('externalIds')->find(DemoSeeder::JENNIFER_ID);
    $jen = Contact::with('externalIds')->find(DemoSeeder::JEN_ID);

    expect($jennifer->externalIds->pluck('external_id')->all())->toBe(['BLM-1001'])
        ->and($jen->externalIds->pluck('external_id')->all())->toBe(['MC-8842']);
});

it('seeds the parent and child sharing an inbox but conflicting on dob', function () {
    $parent = Contact::with('emails')->find(DemoSeeder::PARENT_ID);
    $child = Contact::with('emails')->find(DemoSeeder::CHILD_ID);

    $sharedEmail = $parent->emails->pluck('normalized_value')->intersect($child->emails->pluck('normalized_value'));

    // Everything the naive rule keys on agrees; only `dob` says "different people".
    expect($sharedEmail)->toHaveCount(1)
        ->and($parent->last_name)->toBe($child->last_name)
        ->and($parent->address_key)->toBe($child->address_key)
        ->and($parent->dob->toDateString())->not->toBe($child->dob->toDateString());
});

it('writes every blocking key the candidate generator self-joins on', function () {
    // A null normalized_value silently kills the exact email/phone blocks.
    expect(Contact::whereNull('name_key')->count())->toBe(0)
        ->and(Contact::whereNull('address_key')->count())->toBe(0)
        ->and(DB::table('emails')->whereNull('normalized_value')->count())->toBe(0)
        ->and(DB::table('phones')->whereNull('normalized_value')->count())->toBe(0);
});

it('never lets a noise contact share an email or phone with anyone', function () {
    // The exact blocks are where a noise contact could earn the 30 (email) or 25
    // (phone) points that would carry it into the Review band.
    $emailCollisions = DB::table('emails')
        ->select('normalized_value')
        ->groupBy('normalized_value')
        ->havingRaw('max(contact_id) > ?', [DemoSeeder::LAST_CURATED_ID])
        ->havingRaw('count(*) > 1')
        ->count();

    $phoneCollisions = DB::table('phones')
        ->select('normalized_value')
        ->groupBy('normalized_value')
        ->havingRaw('max(contact_id) > ?', [DemoSeeder::LAST_CURATED_ID])
        ->havingRaw('count(*) > 1')
        ->count();

    expect($emailCollisions)->toBe(0)->and($phoneCollisions)->toBe(0);
});

it('keeps every noise contact out of a household', function () {
    // Households are the only other route to a score: the modifier can boost a
    // pair on name + `dob` agreement. No noise contact belongs to one, so with no
    // shared email or phone either, a noise pair can fire at most name (25) and
    // address (20) — 45, under the 60 Review band. Nothing we did not seed can
    // reach the queue.
    //
    // The exhaustive proof (enumerate every blocked pair, take the max ceiling)
    // needs the GIN trigram indexes to run in reasonable time, so it lives in
    // Detection phase 1 alongside them.
    $noiseInHouseholds = DB::table('household_contacts')
        ->where('contact_id', '>', DemoSeeder::LAST_CURATED_ID)
        ->count();

    expect($noiseInHouseholds)->toBe(0);
});

it('cannot rely on the name trigram to generate the hero pair', function () {
    // The reason hero case 1 must be seeded into a shared household. `jen` against
    // `jennifer` sits at the default 0.3 threshold — a coin flip — and even the
    // full name keys stay well below the similarity of an ordinary typo pair.
    $similarity = DB::selectOne(
        'select similarity(?, ?) as first_name, similarity(?, ?) as name_key',
        ['jen', 'jennifer', 'jen smith', 'jennifer smith'],
    );

    expect((float) $similarity->first_name)->toBeLessThanOrEqual(0.35)
        ->and((float) $similarity->name_key)->toBeLessThan(0.7);
});

it('is deterministic — the fixed Faker seed reproduces the same noise every run', function () {
    // Golden values from `Faker::seed(2024)`. They exist so `seed:demo` can be
    // re-run mid-demo and land on the identical database, and so a careless
    // reordering of Faker calls fails here rather than in a Detection test.
    $first = Contact::where('id', '>', 2000)->orderBy('id')->first();

    expect($first->id)->toBe(2001)
        ->and($first->name_key)->toBe('gerhard satterfield')
        ->and($first->primary_email)->toBe('dooley.braeden@example.com');
});

it('seeds the curated set plus roughly two thousand noise contacts', function () {
    expect(Contact::count())->toBe(2018)
        ->and(Contact::where('id', '<=', DemoSeeder::LAST_CURATED_ID)->count())->toBe(18);
});
