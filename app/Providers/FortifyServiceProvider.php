<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Fortify is intentionally inert in this prototype: all features are disabled
 * in config/fortify.php and auth is stubbed to a seeded demo admin via the
 * AutoLoginDemoAdmin middleware. No login/register flows, views, or actions.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
