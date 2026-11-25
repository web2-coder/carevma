<?php

namespace App\Http\Middleware;

use App\Services\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route middleware `module:<key>`: the signed-in user must hold that module (admins always do). */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next, string $module, string $action = 'view'): Response
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!Modules::allows($user, $module, $action)) {
            $label = Modules::all()[$module]['label'] ?? $module;

            $message = $action === 'view' || !Modules::allows($user, $module)
                ? "You do not have access to the {$label} module."
                : "You do not have permission to {$action} in the {$label} module.";

            return response()->json(['message' => $message], 403);
        }

        return $next($request);
    }
}
