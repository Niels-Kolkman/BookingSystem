<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only lets users with one of the given roles through.
 *
 * Usage: `->middleware('role:admin')` or `->middleware('role:employee,admin')`.
 * Place it after `auth`. This guards whole areas by role; whether a user may
 * see one specific booking or agenda is decided by the policies.
 */
class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // An unknown role name is a programming error: fail loudly (ValueError)
        // instead of silently denying everyone.
        $allowed = array_map(fn (string $role) => UserRole::from($role), $roles);

        $user = $request->user();

        abort_unless($user !== null && $user->hasRole(...$allowed), 403);

        return $next($request);
    }
}
