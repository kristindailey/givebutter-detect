<?php

use App\Models\Contact;
use App\Models\DuplicateCandidate;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * The demo's safety net. One database, many visitors, no terminal: whoever merges
 * the hero pair archives Jennifer/Jen for everyone who comes after, so the deployed
 * demo restores itself on a timer. The in-app reset button is for driving a live
 * demo, where waiting out a cron is not an option; this is for everyone else.
 *
 * It fires only on a demo someone has actually used. Reseeding renumbers
 * `duplicate_candidates`, so an unconditional reset would move `/duplicates/{id}`
 * out from under anyone sitting on a Merge Review page — every ten minutes, forever,
 * even on an idle demo. An untouched demo has nothing to restore, so leave it alone.
 */
Schedule::command('seed:demo --detect --force')
    ->cron(sprintf('*/%d * * * *', (int) config('demo.reset_interval_minutes')))
    // The reset takes ~3s, so a lock older than 5 minutes means the run died with the
    // container rather than finished. `withoutOverlapping()` defaults to a 24-hour
    // expiry, which would wedge the reset for a day — silently, and on the one thing
    // whose whole job is to unwedge the demo.
    ->withoutOverlapping(5)
    // The app cluster can hold more than one instance, and each runs `schedule:run`.
    // The lock lives in the shared database cache store, so exactly one wins.
    ->onOneServer()
    ->when(function (): bool {
        if (! config('demo.reset_enabled')) {
            return false;
        }

        // A merge resolves the pair *and* archives the loser, and a dismissal
        // resolves the pair — so either check alone would do. Both are here so any
        // dirty state heals, not just the two the UI can produce.
        return DuplicateCandidate::query()
            ->where('resolution', '!=', DuplicateCandidate::RESOLUTION_PENDING)
            ->exists()
            || Contact::withArchived()->whereNotNull('archived_at')->exists();
    });
