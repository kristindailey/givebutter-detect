<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * GIN `gin_trgm_ops` indexes backing the two fuzzy blocking self-joins.
     *
     * The `%` operator in `CandidateGenerator` (`a.name_key % b.name_key`) probes
     * these indexes instead of materializing the full cross product — an index
     * scan, not a nested loop over everything. Unindexed, the trigram self-join
     * over the 2k demo seed is ~2.5s; indexed it is fast enough to run inside a
     * test. `pg_trgm` is enabled by an earlier migration.
     */
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS contacts_name_key_trgm ON contacts USING gin (name_key gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS contacts_address_key_trgm ON contacts USING gin (address_key gin_trgm_ops)');
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS contacts_address_key_trgm');
        DB::statement('DROP INDEX IF EXISTS contacts_name_key_trgm');
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
