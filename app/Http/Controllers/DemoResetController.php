<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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
 * It runs inline rather than on the queue because the whole reset is ~3s (1.0s
 * seed + 1.2s ANALYZE + 0.8s rescore) — not worth a job, a worker, and a polling
 * UI to go with it.
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

        $startedAt = microtime(true);

        Artisan::call('seed:demo', ['--detect' => true, '--force' => true]);

        $this->logResetTiming($startedAt);

        return to_route('duplicates.index');
    }

    /**
     * TEMPORARY — diagnostic, remove once it has answered the question.
     *
     * This reset takes ~22s as a web request but ~3.4s as the same command in Cloud's
     * console, on the same instance against the same database. Postgres ramp-up and PHP
     * memory pressure are both already ruled out by measurement, so rather than guess a
     * third time, this reproduces `seed:demo`'s own per-task timing table *in the web
     * context* — `Artisan::call()` captures the command's output, timings and all.
     *
     * If the tasks match the console but `total_ms` is ~22000, the cost is between them.
     */
    private function logResetTiming(float $startedAt): void
    {
        Log::info('demo reset timing', [
            'total_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1_048_576, 1),
            'memory_limit' => ini_get('memory_limit'),
            'output' => Artisan::output(),
        ]);
    }
}
