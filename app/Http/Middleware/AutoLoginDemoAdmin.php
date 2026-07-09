<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stubs authentication for the prototype: logs the seeded demo admin in on every
 * request so Auth::user() always resolves, with no login flow. In production this
 * sits behind Givebutter's org-scoped auth (see README).
 */
class AutoLoginDemoAdmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            $admin = User::where('email', User::DEMO_ADMIN_EMAIL)->first();

            if ($admin !== null) {
                Auth::login($admin);
            }
        }

        return $next($request);
    }
}
