<?php

use App\Models\Contact;
use App\Models\DuplicateCandidate;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The demo's reset path. The deployed URL shares one database across every
 * visitor and none of them have a terminal, so a merge of the hero pair is
 * otherwise permanent — the demo has to be able to restore itself.
 *
 * Both the scheduled reset and the in-app button run `seed:demo --detect`, so
 * that command is what these tests exercise.
 */

/** Would the scheduled reset fire right now? */
function scheduledResetWouldRun(): bool
{
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'seed:demo'));

    expect($event)->not->toBeNull();

    return $event->filtersPass(app());
}
it('resets the dataset and refills the queue in one command', function () {
    $this->seed(DemoSeeder::class);
    $this->artisan('detect:run')->assertSuccessful();

    $hero = DuplicateCandidate::query()
        ->where('contact_a_id', DemoSeeder::JENNIFER_ID)
        ->where('contact_b_id', DemoSeeder::JEN_ID)
        ->firstOrFail();

    // Simulate the demo having been run: Jen archived, the pair resolved.
    Contact::findOrFail(DemoSeeder::JEN_ID)->archive();
    $hero->markMerged();

    $this->artisan('seed:demo --detect --force')->assertSuccessful();

    $restored = DuplicateCandidate::pending()
        ->where('contact_a_id', DemoSeeder::JENNIFER_ID)
        ->where('contact_b_id', DemoSeeder::JEN_ID)
        ->first();

    expect(Contact::findOrFail(DemoSeeder::JEN_ID)->archived_at)->toBeNull()
        ->and($restored)->not->toBeNull()
        ->and($restored->score)->toBe('94.00');
});

it('leaves the queue empty without the flag, so detect:run stays a separate batch step', function () {
    $this->artisan('seed:demo --force')->assertSuccessful();

    expect(DuplicateCandidate::count())->toBe(0);
});

it('exposes the reset to the app so a live demo can re-run without a terminal', function () {
    $this->seed(DemoSeeder::class);
    Contact::findOrFail(DemoSeeder::JEN_ID)->archive();

    $this->post(route('demo.reset'))->assertRedirect(route('duplicates.index'));

    expect(Contact::findOrFail(DemoSeeder::JEN_ID)->archived_at)->toBeNull()
        ->and(DuplicateCandidate::pending()->count())->toBeGreaterThan(0);
});

it('hides the reset route when the demo flag is off', function () {
    config()->set('demo.reset_enabled', false);

    $this->post(route('demo.reset'))->assertNotFound();
});

/**
 * The scheduled reset only fires on a demo someone has used. Reseeding renumbers
 * `duplicate_candidates`, so resetting an untouched demo every ten minutes would
 * move `/duplicates/{id}` out from under anyone reading a Merge Review page — a
 * dead link produced by a reset that had nothing to restore.
 */
it('leaves an untouched demo alone, so a Merge Review link survives', function () {
    $this->seed(DemoSeeder::class);
    $this->artisan('detect:run')->assertSuccessful();

    expect(scheduledResetWouldRun())->toBeFalse();
});

it('fires once a pair has been dismissed', function () {
    $this->seed(DemoSeeder::class);
    $this->artisan('detect:run')->assertSuccessful();

    DuplicateCandidate::pending()->firstOrFail()->markDismissed();

    expect(scheduledResetWouldRun())->toBeTrue();
});

it('fires once a contact has been archived by a merge', function () {
    $this->seed(DemoSeeder::class);
    $this->artisan('detect:run')->assertSuccessful();

    Contact::findOrFail(DemoSeeder::JEN_ID)->archive();

    expect(scheduledResetWouldRun())->toBeTrue();
});

it('never fires when the demo flag is off, however dirty the data', function () {
    $this->seed(DemoSeeder::class);
    $this->artisan('detect:run')->assertSuccessful();
    Contact::findOrFail(DemoSeeder::JEN_ID)->archive();

    config()->set('demo.reset_enabled', false);

    expect(scheduledResetWouldRun())->toBeFalse();
});
