<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $knownRoles = [
            User::ROLE_PLATFORM_ADMIN,
            User::ROLE_ORG_ADMIN,
            User::ROLE_INVENTORY_AGENT,
        ];

        if (! $user instanceof User || ! in_array($user->role, $knownRoles, true)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $allowedRoles = array_values(array_intersect($roles, $knownRoles));

        if ($allowedRoles === [] || ! in_array($user->role, $allowedRoles, true)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
