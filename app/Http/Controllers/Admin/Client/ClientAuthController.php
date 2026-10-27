<?php

namespace App\Http\Controllers\Admin\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ClientAuthController extends Controller
{
    public function showSetPasswordForm(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
        ]);

        abort_unless($this->validResetToken($request->email, $request->token), 403, 'Invalid or expired link.');
        abort_unless(User::where('email', $request->email)->where('role', 'client')->exists(), 404);

        return view('client.auth.set-password', [
            'email' => $request->email,
            'token' => $request->token,
        ]);
    }

    public function setPassword(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!$this->validResetToken($request->email, $request->token)) {
            return back()->with('error', 'Invalid or expired link.');
        }

        $user = User::where('email', $request->email)->where('role', 'client')->firstOrFail();
        $user->update(['password' => Hash::make($request->password)]);

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return redirect()->route('login')->with('success', 'Password set successfully. You can login now.');
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();
        abort_unless($user && $user->role === 'client', 403);

        if (!Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        return back()->with('success', 'Password changed successfully.');
    }

    private function validResetToken(string $email, string $token): bool
    {
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();
        if (!$row || !Hash::check($token, $row->token) || !$row->created_at) return false;

        return Carbon::parse($row->created_at)
            ->addMinutes((int) config('auth.passwords.users.expire', 60))
            ->isFuture();
    }
}
