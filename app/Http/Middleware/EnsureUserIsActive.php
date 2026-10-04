<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appended to the `web` group in bootstrap/app.php: a deactivated account is
 * signed out on its very next request, whatever route it hits.
 *
 * Login already refuses inactive users and User::logOutEverywhere() deletes
 * their database sessions, but neither covers a session that is re-created
 * from a "Remember me" cookie, and the `role` middleware and `auth`-only
 * routes (dashboard, profile, stock, cutting) never looked at is_active.
 * Policies do, which is why most screens 403'd — this closes the rest.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['login' => 'This account has been deactivated. Contact the office.']);
        }

        return $next($request);
    }
}
