<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class OrgGoogleController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function redirectToGoogleLogin()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        $googleUser = Socialite::driver('google')->stateless()->user();

        if (Auth::check()) {
            $user = Auth::user();
            abort_unless($user->role === 'employee', 403);

            if (strcasecmp((string) $googleUser->email, (string) $user->email) !== 0) {
                return redirect()->route('employee.setting')
                    ->withErrors('Google email must match your registered email.');
            }

            $user->update(['google_id' => $googleUser->id]);
            return redirect()->route('employee.setting')->with('success', 'Google account connected successfully.');
        }

        $user = User::where('google_id', $googleUser->id)->first();
        if (!$user || strcasecmp((string) $googleUser->email, (string) $user->email) !== 0) {
            return redirect()->route('login')->withErrors('Your account is not connected to this Google account.');
        }

        Auth::login($user, true);
        request()->session()->regenerate();

        return redirect($this->dashboardFor($user->role));
    }

    public function disconnectGoogle(Request $request)
    {
        $request->user()->update(['google_id' => null]);
        return redirect()->route('employee.setting')->with('success', 'Google account disconnected successfully.');
    }

    private function dashboardFor(string $role): string
    {
        return match ($role) {
            'admin', 'superadmin' => '/admin/dashboard',
            'hr' => '/hr/dashboard',
            'employee' => '/employee/dashboard',
            'client' => '/doctor/dashboard',
            default => '/login',
        };
    }
}
