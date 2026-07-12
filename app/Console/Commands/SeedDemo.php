<?php

namespace App\Console\Commands;

use Database\Seeders\DemoAdminSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Resets the curated demo data so the Jennifer/Jen merge can be re-run after a
 * dry run, without a full `migrate:fresh --seed`.
 *
 * Clears the domain tables only — the demo admin that `AutoLoginDemoAdmin` resolves
 * on every request is never dropped, so the app stays usable across a reset. It is
 * seeded here rather than assumed, because this command is the whole seeding story
 * on the deployed demo: the release runs `migrate --force` and then this, never
 * `db:seed`, so nothing else would create the user.
 */
#[Signature('seed:demo {--detect : Rescore the queue afterwards, for a reset in one command} {--force : Run without confirmation in production}')]
#[Description('Reset the curated demo dataset (hero cases, review-band pairs, and noise)')]
class SeedDemo extends Command
{
    use ConfirmableTrait;

    /**
     * Child-to-parent order. `households` precedes `contacts` because it holds a
     * `head_contact_id` foreign key back to them.
     *
     * @var list<string>
     */
    private const array DOMAIN_TABLES = [
        'duplicate_candidates',
        'transactions',
        'contact_tags',
        'household_contacts',
        'external_ids',
        'addresses',
        'phones',
        'emails',
        'tags',
        'households',
        'contacts',
    ];

    public function handle(DemoSeeder $seeder, DemoAdminSeeder $adminSeeder): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $this->components->task('Clearing demo data', function () {
            DB::transaction(function () {
                foreach (self::DOMAIN_TABLES as $table) {
                    DB::table($table)->delete();
                }
            });
        });

        $this->components->task('Seeding demo admin', fn () => $adminSeeder->setCommand($this)->run());

        $this->components->task('Seeding demo data', fn () => $seeder->setCommand($this)->run());

        // Opt-in, so the README's two-step first run still shows `detect:run` as the
        // separate batch job it is. The reset paths — scheduler, in-app button, deploy
        // release command — want the whole thing in one command instead.
        if ($this->option('detect')) {
            $this->call(DetectRun::class);
        }

        $this->newLine();
        $this->components->info(sprintf(
            'Demo data ready. Hero pair: %d (Jennifer) / %d (Jen).',
            DemoSeeder::JENNIFER_ID,
            DemoSeeder::JEN_ID,
        ));

        return self::SUCCESS;
    }
}
