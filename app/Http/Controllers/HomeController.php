<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = Auth::user();

        if (!$user) {
            return redirect('/login');
        }

        if ($user->role === 'employee') {
            $user->loadMissing('employee');
            if ($user->employee && !$user->employee->profile_completed) {
                return redirect('/employee/profile');
            }
        }

        return redirect(match ($user->role) {
            'admin', 'superadmin' => '/admin/dashboard',
            'hr' => '/hr/dashboard',
            'employee' => '/employee/dashboard',
            'client' => '/doctor/dashboard',
            default => '/login',
        });
    }
}
