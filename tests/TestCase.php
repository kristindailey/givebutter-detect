<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;
use Laravel\Fortify\Features;
use PDO;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * The suite runs on Postgres, not SQLite — `pg_trgm` is the matcher, so
     * testing against a database without it would prove nothing about blocking.
     *
     * `RefreshDatabase` calls this once, immediately before it migrates, so this
     * is where the dedicated test database gets created if it isn't there yet.
     * Keeps `composer test` working from a clean checkout with no extra step.
     *
     * No native return type: Pest re-declares this hook from the trait's own
     * untyped signature, and a `: void` here breaks the generated subclass.
     *
     * @return void
     */
    protected function beforeRefreshingDatabase()
    {
        $this->ensureTestDatabaseExists();
    }

    private function ensureTestDatabaseExists(): void
    {
        if (Config::get('database.default') !== 'pgsql') {
            return;
        }

        /** @var array<string, mixed> $connection */
        $connection = Config::get('database.connections.pgsql');

        $database = (string) $connection['database'];

        // Connect to the `postgres` maintenance database to ask whether ours exists.
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $connection['host'], $connection['port']);

        $pdo = new PDO($dsn, (string) $connection['username'], (string) $connection['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $statement = $pdo->prepare('select 1 from pg_database where datname = ?');
        $statement->execute([$database]);

        if ($statement->fetchColumn() === false) {
            // Postgres has no CREATE DATABASE IF NOT EXISTS, and the name cannot be
            // bound as a parameter — it comes from config, never from user input.
            $pdo->exec(sprintf('create database "%s"', str_replace('"', '', $database)));
        }
    }
}
