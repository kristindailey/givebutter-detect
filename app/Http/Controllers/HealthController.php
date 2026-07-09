<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Foundation health check: proves the stack is wired end-to-end (DB, pg_trgm,
 * stubbed auth) before any feature work. React/Vite hydration is asserted
 * client-side by the page itself.
 */
class HealthController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('health', [
            'checks' => [
                $this->databaseCheck(),
                $this->pgTrgmCheck(),
                $this->authCheck(),
            ],
        ]);
    }

    /**
     * @return array{key: string, label: string, ok: bool, detail: string}
     */
    private function databaseCheck(): array
    {
        try {
            $connection = DB::connection();
            $connection->getPdo();

            return $this->result('database', 'Database connection', true, sprintf(
                'Connected to %s (%s)',
                $connection->getDatabaseName(),
                $connection->getDriverName(),
            ));
        } catch (Throwable $e) {
            return $this->result('database', 'Database connection', false, $e->getMessage());
        }
    }

    /**
     * @return array{key: string, label: string, ok: bool, detail: string}
     */
    private function pgTrgmCheck(): array
    {
        try {
            $extensions = collect(DB::select(
                "SELECT extname FROM pg_extension WHERE extname IN ('pg_trgm', 'unaccent')"
            ))->pluck('extname');

            $hasTrgm = $extensions->contains('pg_trgm');

            return $this->result('pg_trgm', 'pg_trgm extension', $hasTrgm, $hasTrgm
                ? 'Enabled: '.$extensions->implode(', ')
                : 'pg_trgm not found — run migrate');
        } catch (Throwable $e) {
            return $this->result('pg_trgm', 'pg_trgm extension', false, $e->getMessage());
        }
    }

    /**
     * @return array{key: string, label: string, ok: bool, detail: string}
     */
    private function authCheck(): array
    {
        $user = Auth::user();

        return $this->result('auth', 'Demo admin auto-logged-in', $user !== null, $user !== null
            ? 'Authenticated as '.$user->email
            : 'No authenticated user');
    }

    /**
     * @return array{key: string, label: string, ok: bool, detail: string}
     */
    private function result(string $key, string $label, bool $ok, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'ok' => $ok,
            'detail' => $detail,
        ];
    }
}
