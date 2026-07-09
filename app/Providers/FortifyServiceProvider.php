<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

/**
 * Fortify is intentionally inert in this prototype: all features are disabled
 * in config/fortify.php and auth is stubbed to a seeded demo admin via the
 * AutoLoginDemoAdmin middleware. No login/register flows, views, or actions.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Suppress Fortify's core routes (login, logout, password confirmation)
        // too — auth is handled entirely by AutoLoginDemoAdmin. Must run before
        // the package provider boots and loads its routes.
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        //
    }
}
