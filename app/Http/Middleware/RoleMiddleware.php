<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (!$user) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Unauthenticated.'], 401)
                : redirect('/login');
        }

        $allowed = in_array($user->role, $roles, true) || ($user->role === 'superadmin' && in_array('admin', $roles, true));

        if (!$allowed) {
            return $request->expectsJson()
                ? response()->json(['message' => 'You do not have permission to perform this action.'], 403)
                : redirect('/');
        }

        $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        return $next($request);
    }
}
