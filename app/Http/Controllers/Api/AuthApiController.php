<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Employee;
use App\Services\TrustedTimeService;
use App\Services\BirthdayReminderService;
use App\Services\ClientTimezoneService;
use Carbon\Carbon;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthApiController extends Controller
{
    public function __construct(
        private TrustedTimeService $clock,
        private BirthdayReminderService $birthdayReminders,
        private ClientTimezoneService $timezones,
    ) {
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            return response()->json(['message' => 'The email or password is incorrect.'], 422);
        }

        $request->session()->regenerate();
        $user = $request->user()->loadMissing(['employee', 'client']);

        return response()->json([
            'message' => 'Signed in successfully.',
            'user' => $this->userPayload($user),
            'redirect' => $this->landingFor($user),
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['user' => null], 401);
        }

        $user->loadMissing(['employee', 'client']);
        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function time(Request $request)
    {
        return response()->json([
            'utc' => $this->clock->now('UTC')->toIso8601ZuluString(),
            'source' => $this->clock->source(),
            'timezone' => $this->assignedTimezone($request->user()->loadMissing(['employee', 'client'])),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => 'Signed out.']);
    }

    public function forgot(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 422);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                event(new PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 422);
    }

    private function landingFor(User $user): string
    {
        if ($user->role === 'employee' && $user->employee && !$user->employee->hasCompleteSelfServiceProfile()) {
            return '/employee/profile';
        }

        return $this->dashboardFor($user->role);
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

    private function userPayload(User $user): array
    {
        if ($user->role === 'employee' && $user->employee) {
            $actuallyComplete = $user->employee->hasCompleteSelfServiceProfile();
            if ((bool) $user->employee->profile_completed !== $actuallyComplete) {
                $user->employee->forceFill(['profile_completed' => $actuallyComplete])->saveQuietly();
                $user->employee->refresh();
            }
        }

        $assignedTimezone = $this->assignedTimezone($user);

        if (in_array($user->role, ['admin', 'superadmin'], true)) {
            $this->birthdayReminders->ensureForUser($user);
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'profile_picture' => $user->profile_picture,
            'last_seen_at' => optional($user->last_seen_at)->toIso8601String(),
            'employee' => $user->employee,
            'client' => $user->client,
            // One canonical timezone for the navbar clock. Never infer this from the browser.
            'assigned_timezone' => $assignedTimezone,
            'timezone' => $assignedTimezone,
            'dashboard' => $this->landingFor($user),
            'birthday' => $this->birthdayPayload($user, $assignedTimezone),
        ];
    }


    private function birthdayPayload(User $user, string $assignedTimezone): array
    {
        if ($user->role === 'employee') {
            $employee = $user->employee;
            $now = $this->clock->now($assignedTimezone);
            $birthDate = $employee?->date_of_birth;
            $isToday = $birthDate
                ? ((int) $birthDate->format('m') === (int) $now->format('m')
                    && (int) $birthDate->format('d') === (int) $now->format('d'))
                : false;

            return [
                'today' => $isToday,
                'date_key' => $now->toDateString(),
                'employees' => $isToday && $employee ? [[
                    'id' => $employee->id,
                    'name' => trim($employee->first_name.' '.$employee->last_name),
                    'timezone' => $assignedTimezone,
                    'profile_picture' => $employee->profile_picture,
                    'age' => max(0, $now->year - (int) $birthDate->format('Y')),
                ]] : [],
            ];
        }

        if (in_array($user->role, ['admin', 'superadmin', 'hr'], true)) {
            $birthdays = Employee::query()
                ->whereNotNull('date_of_birth')
                ->get(['id', 'first_name', 'last_name', 'date_of_birth', 'timezone', 'profile_picture'])
                ->filter(function (Employee $employee) {
                    $timezone = $this->timezones->valid($employee->timezone, $this->timezones->businessTimezone());
                    $today = $this->clock->now($timezone);
                    $birthDate = $employee->date_of_birth;

                    return $birthDate
                        && (int) $birthDate->format('m') === (int) $today->format('m')
                        && (int) $birthDate->format('d') === (int) $today->format('d');
                })
                ->map(function (Employee $employee) {
                    $timezone = $this->timezones->valid($employee->timezone, $this->timezones->businessTimezone());
                    $today = $this->clock->now($timezone);

                    return [
                        'id' => $employee->id,
                        'name' => trim($employee->first_name.' '.$employee->last_name),
                        'timezone' => $timezone,
                        'profile_picture' => $employee->profile_picture,
                        'age' => max(0, $today->year - (int) $employee->date_of_birth->format('Y')),
                    ];
                })
                ->values()
                ->all();

            return [
                'today' => count($birthdays) > 0,
                'date_key' => $this->clock->now($this->timezones->businessTimezone())->toDateString(),
                'employees' => $birthdays,
            ];
        }

        return [
            'today' => false,
            'date_key' => $this->clock->now($assignedTimezone)->toDateString(),
            'employees' => [],
        ];
    }

    private function assignedTimezone(User $user): string
    {
        return match ($user->role) {
            'employee' => $this->timezones->valid($user->employee?->timezone, $this->timezones->businessTimezone()),
            'client' => $this->timezones->valid($user->client?->timezone, $this->timezones->businessTimezone()),
            default => $this->timezones->businessTimezone(),
        };
    }

}
