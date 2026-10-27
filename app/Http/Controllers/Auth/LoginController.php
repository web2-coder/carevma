<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\AuthenticatesUsers;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
protected function authenticated($request, $user)
{
    // Chahe user ka role kuch bhi ho, client ke liye special redirect
    if ($user->role === 'client') {
        return redirect('/doctor/dashboard');
    }

    // Baaki roles normal
    return match ($user->role) {
        'admin'    => redirect('/admin/dashboard'),
        'employee' => redirect('/employee/dashboard'),
        'client'   => redirect('/doctor/dashboard'),
        default    => redirect('/home'),
    };
}

    // protected function redirectTo()
    // {
    //     $role = Auth::user()->role;

    //     return match ($role) {
    //         'admin'      => 'admin/dashboard',
    //         'superadmin' => '/superadmin/dashboard',
    //         'client'     => '/doctor/dashboard',
    //         'employee'   => '/employee/dashboard',
    //          default      => '/home',
    //     };
    // }

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }
}
