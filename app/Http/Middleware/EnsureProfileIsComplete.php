<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureProfileIsComplete
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $employee = $user?->employee;

        if ($user && $user->role === 'employee' && $employee) {
            $actuallyComplete = $employee->hasCompleteSelfServiceProfile();
            if ((bool) $employee->profile_completed !== $actuallyComplete) {
                $employee->forceFill(['profile_completed' => $actuallyComplete])->saveQuietly();
            }

            if (!$actuallyComplete) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Complete your required profile fields before using the rest of CareVMA.',
                    'code' => 'PROFILE_INCOMPLETE',
                    'redirect' => '/employee/profile',
                    'missing_fields' => $employee->missingRequiredProfileFields(),
                ], 423);
            }

            if (!$request->routeIs('employee.profile.*')) {
                return redirect()->route('employee.profile.edit')
                    ->with('warning', 'Please complete your profile before accessing other sections.');
            }
            }
        }

        return $next($request);
    }
}
