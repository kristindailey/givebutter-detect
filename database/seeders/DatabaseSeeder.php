<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The single demo admin that AutoLoginDemoAdmin logs in on every request.
        // Idempotent so migrate:fresh --seed and seed:demo can rerun cleanly.
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
