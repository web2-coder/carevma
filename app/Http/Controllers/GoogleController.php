<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Auth;

class GoogleController extends Controller
{
    // Redirect to Google to connect account
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    // Handle Google callback after connecting
    public function handleGoogleCallback()
    {
        $googleUser = Socialite::driver('google')->stateless()->user();
        $user = Auth::user();

        // Only allow connecting with the same email
        if ($googleUser->email !== $user->email) {
            return redirect()->route('employee.dashboard')
                ->withErrors('Google account email must match your registered email.');
        }

        $user->google_id = $googleUser->id;
        $user->save();

        return redirect()->route('employee.dashboard')
            ->with('success', 'Google account connected successfully!');
    }

    // Login with Google
    public function loginWithGoogle()
    {
        $googleUser = Socialite::driver('google')->stateless()->user();

        $user = \App\Models\User::where('google_id', $googleUser->id)
            ->orWhere('email', $googleUser->email)
            ->first();

        if ($user && $user->email === $googleUser->email) {
            Auth::login($user);
            return redirect()->route('employee.dashboard');
        }

        return redirect()->route('login')
            ->withErrors('No employee account found with this Google email.');
    }
}
