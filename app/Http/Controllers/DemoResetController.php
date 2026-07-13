<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Reset demo data" — restores the curated dataset and rescores the queue, so the
 * Jennifer/Jen merge can be run again without a terminal.
 *
 * This exists because the deployed demo shares one database across every visitor:
 * a merge is permanent, and the hero case is gone for whoever comes next. The
 * scheduled reset (`routes/console.php`) covers the unattended case; this covers
 * driving a live demo, where waiting out the cron isn't an option.
 *
 * It runs inline rather than on the queue because the whole reset is a few seconds
 * — not worth a job, a worker, and a polling UI to go with it. That is only true
 * because the seeder `VACUUM`s: without it the post-seed `detect:run` pays a
 * first-touch tax on every freshly written row and the reset takes ~20s deployed,
 * which is what the button used to do.
 *
 * Guards: the `demo.reset_enabled` flag, plus a rate limit on the route. This is a
 * destructive endpoint on an app whose auth is deliberately stubbed, so it is
 * narrow on purpose — no parameters, one fixed command, nothing a caller can steer.
 */
class DemoResetController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        abort_unless((bool) config('demo.reset_enabled'), Response::HTTP_NOT_FOUND);

        Artisan::call('seed:demo', ['--detect' => true, '--force' => true]);

        return to_route('duplicates.index');
    }
}
