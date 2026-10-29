<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Employee;
use App\Models\BirthdayWish;
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

    public function markBirthdayPopupSeen(Request $request)
    {
        $user = $request->user()->loadMissing(['employee', 'client']);
        $timezone = $this->assignedTimezone($user);
        $date = $this->clock->now($timezone)->toDateString();
        $user->forceFill(['birthday_popup_seen_date' => $date])->save();

        return response()->json(['message' => 'Birthday popup marked as shown.', 'date_key' => $date]);
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
        $today = $this->clock->now($assignedTimezone);
        $dateKey = $today->toDateString();
        $birthdays = Employee::query()
            ->whereNotNull('date_of_birth')
            ->get(['id', 'user_id', 'first_name', 'last_name', 'date_of_birth', 'timezone', 'profile_picture'])
            ->filter(function (Employee $employee) use ($today) {
                $birthDate = $employee->date_of_birth;
                return $birthDate
                    && (int) $birthDate->format('m') === (int) $today->format('m')
                    && (int) $birthDate->format('d') === (int) $today->format('d');
            })
            ->values();

        $selfEmployeeId = $user->role === 'employee' ? $user->employee?->id : null;
        $sentIds = collect();
        $received = collect();
        if ($user->role === 'employee') {
            $sentIds = BirthdayWish::query()
                ->where('sender_user_id', $user->id)
                ->whereDate('birthday_date', $dateKey)
                ->pluck('recipient_employee_id');

            if ($selfEmployeeId && $birthdays->contains('id', $selfEmployeeId)) {
                $received = BirthdayWish::query()
                    ->with('sender:id,name,profile_picture')
                    ->where('recipient_employee_id', $selfEmployeeId)
                    ->whereDate('birthday_date', $dateKey)
                    ->latest('id')
                    ->get()
                    ->map(fn (BirthdayWish $wish) => [
                        'id' => $wish->id,
                        'sender_id' => $wish->sender_user_id,
                        'sender_name' => $wish->sender?->name ?: 'CareVMA teammate',
                        'sender_picture' => $wish->sender?->profile_picture,
                        'sent_at' => optional($wish->created_at)->toIso8601String(),
                    ]);
            }
        }

        $people = $birthdays->map(function (Employee $employee) use ($assignedTimezone, $today, $sentIds, $selfEmployeeId) {
            return [
                'id' => $employee->id,
                'user_id' => $employee->user_id,
                'name' => trim($employee->first_name.' '.$employee->last_name),
                // Employee portal uses its current Doctor timezone consistently.
                'timezone' => $assignedTimezone,
                'profile_picture' => $employee->profile_picture,
                'age' => max(0, $today->year - (int) $employee->date_of_birth->format('Y')),
                'is_self' => $selfEmployeeId === $employee->id,
                'sticker_sent' => $sentIds->contains($employee->id),
            ];
        })->values()->all();

        return [
            'today' => count($people) > 0,
            'date_key' => $dateKey,
            'self_employee_id' => $selfEmployeeId,
            'is_self_birthday' => $selfEmployeeId ? $birthdays->contains('id', $selfEmployeeId) : false,
            'employees' => $people,
            'stickers_received' => $received->values()->all(),
            'popup_seen' => optional($user->birthday_popup_seen_date)->toDateString() === $dateKey,
        ];
    }

    private function assignedTimezone(User $user): string
    {
        return match ($user->role) {
            'employee' => $this->employeeDoctorTimezone($user->employee),
            'client' => $this->timezones->valid($user->client?->timezone, $this->timezones->businessTimezone()),
            default => $this->timezones->businessTimezone(),
        };
    }

    private function employeeDoctorTimezone(?Employee $employee): string
    {
        if (!$employee) {
            return $this->timezones->businessTimezone();
        }

        $active = $employee->attendances()
            ->with('client:id,timezone')
            ->whereNull('clock_out')
            ->latest('id')
            ->first();
        if ($active?->client?->timezone) {
            return $this->timezones->valid($active->client->timezone, $this->timezones->businessTimezone());
        }

        $nowUtc = $this->clock->now('UTC');
        $next = $employee->clientSchedules()
            ->with('client:id,timezone')
            ->where(function ($query) {
                $query->where('enabled', true)->orWhereNull('enabled');
            })
            ->get()
            ->map(function ($schedule) use ($nowUtc) {
                $zone = $this->timezones->valid($schedule->client?->timezone ?: $schedule->timezone, $this->timezones->businessTimezone());
                $localNow = $nowUtc->copy()->setTimezone($zone);

                if ($schedule->schedule_date) {
                    $date = Carbon::parse($schedule->schedule_date)->toDateString();
                    $start = Carbon::parse($date.' '.$schedule->start_time, $zone);
                    if ($start->lt($localNow->copy()->subMinutes(15))) return null;
                    return ['timezone' => $zone, 'start_utc' => $start->utc()->timestamp];
                }

                for ($offset = 0; $offset <= 7; $offset++) {
                    $date = $localNow->copy()->addDays($offset);
                    if (strcasecmp($date->format('l'), (string) $schedule->weekday) !== 0) continue;
                    $start = Carbon::parse($date->toDateString().' '.$schedule->start_time, $zone);
                    if ($start->lt($localNow->copy()->subMinutes(15))) continue;
                    return ['timezone' => $zone, 'start_utc' => $start->utc()->timestamp];
                }

                return null;
            })
            ->filter()
            ->sortBy('start_utc')
            ->first();

        return $next['timezone'] ?? $this->timezones->valid($employee->timezone, $this->timezones->businessTimezone());
    }

}
