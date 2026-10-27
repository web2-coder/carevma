<?php

namespace App\Services;

use App\Events\CareVmaNotificationBroadcast;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class BirthdayReminderService
{
    public function __construct(private TrustedTimeService $clock)
    {
    }

    public function ensureForAllAdmins(): void
    {
        User::query()
            ->whereIn('role', ['admin', 'superadmin'])
            ->get()
            ->each(fn (User $user) => $this->ensureForUser($user));
    }

    public function ensureForUser(User $user): void
    {
        if (!in_array($user->role, ['admin', 'superadmin'], true)) {
            return;
        }

        Employee::query()
            ->whereNotNull('date_of_birth')
            ->get(['id', 'first_name', 'last_name', 'date_of_birth', 'timezone', 'profile_picture'])
            ->each(function (Employee $employee) use ($user) {
                $timezone = $employee->timezone ?: config('carevma.business_timezone', 'Asia/Karachi');
                $tomorrow = $this->clock->now($timezone)->addDay();
                $birthDate = $employee->date_of_birth;

                if (!$birthDate
                    || (int) $birthDate->format('m') !== (int) $tomorrow->format('m')
                    || (int) $birthDate->format('d') !== (int) $tomorrow->format('d')) {
                    return;
                }

                $birthdayDate = $tomorrow->toDateString();
                $exists = Notification::query()
                    ->where('user_id', $user->id)
                    ->where('type', 'BirthdayTomorrow')
                    ->where('data->employee_id', $employee->id)
                    ->where('data->birthday_date', $birthdayDate)
                    ->exists();

                if ($exists) {
                    return;
                }

                $name = trim($employee->first_name.' '.$employee->last_name);
                $notification = Notification::create([
                    'user_id' => $user->id,
                    'type' => 'BirthdayTomorrow',
                    'title' => 'Birthday tomorrow: '.$name,
                    'message' => $name.' has a birthday tomorrow ('.$birthdayDate.', '.$timezone.').',
                    'action_url' => '/admin/dashboard',
                    'data' => [
                        'employee_id' => $employee->id,
                        'employee_name' => $name,
                        'birthday_date' => $birthdayDate,
                        'timezone' => $timezone,
                        'profile_picture' => $employee->profile_picture,
                    ],
                    'is_read' => false,
                ]);

                try {
                    CareVmaNotificationBroadcast::dispatch($notification);
                } catch (\Throwable $exception) {
                    Log::warning('Birthday reminder saved but realtime broadcast failed.', [
                        'notification_id' => $notification->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
    }
}
