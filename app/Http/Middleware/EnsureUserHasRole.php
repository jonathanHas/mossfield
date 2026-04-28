<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Register as `role` in bootstrap/app.php. Usage: `role:admin` or `role:admin,office`.
 * Aborts 403 with a role-aware message for authenticated users lacking a listed role.
 * An unauthenticated request is pushed to the login page via `auth` middleware, which
 * must be applied *before* this one in the route definition.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $allowed = array_map(fn (string $r) => UserRole::from($r), $roles);

        foreach ($allowed as $role) {
            if ($user->role === $role) {
                return $next($request);
            }
        }

        abort(403, 'You do not have permission to access this page.');
    }
}
