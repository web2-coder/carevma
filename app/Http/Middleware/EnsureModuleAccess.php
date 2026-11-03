<?php

namespace App\Http\Middleware;

use App\Services\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware `module:<key>`: the signed-in user must hold that module (admins always do). */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!Modules::allows($user, $module)) {
            $label = Modules::all()[$module]['label'] ?? $module;

            return response()->json(['message' => "You do not have access to the {$label} module."], 403);
        }

        return $next($request);
    }
}
