<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The single demo admin that `AutoLoginDemoAdmin` logs in on every request.
 *
 * Split out of `DatabaseSeeder` because the deployed demo never runs it: the
 * release command is `migrate --force` + `seed:demo`, which would seed contacts
 * but no user, leaving `Auth::user()` null on a fresh database. Both the seeder
 * and the `seed:demo` command call this, so every path that builds a demo
 * produces one that can authenticate.
 *
 * Idempotent, so `seed:demo` can rerun it on every reset.
 */
class DemoAdminSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::firstOrCreate(
            ['email' => User::DEMO_ADMIN_EMAIL],
            [
                'name' => 'Demo Admin',
                // Login is stubbed, so this is never used — the column is just NOT NULL.
                'password' => 'password',
            ],
        );
    }
}
