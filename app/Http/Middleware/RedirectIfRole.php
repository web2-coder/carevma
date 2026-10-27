<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RedirectIfRole
{
    protected function authenticated($request, $user)
    {
        $destination = match ($user->role) {
            'admin', 'superadmin' => '/admin/dashboard',
            'hr' => '/hr/dashboard',
            'employee' => ($user->employee && !$user->employee->profile_completed)
                ? '/employee/profile'
                : '/employee/dashboard',
            'client' => '/doctor/dashboard',
            default => '/login',
        };

        return redirect($destination);
    }
}
