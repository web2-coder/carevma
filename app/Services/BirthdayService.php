<?php

namespace App\Services;

use App\Events\CareVmaNotificationBroadcast;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BirthdayService
{
    public function __construct(private TrustedTimeService $clock)
    {
    }

    public function payloadFor(User $user): array
    {
        $user->loadMissing(['employee', 'client']);

        if ($user->role === 'employee') {
            $employee = $user->employee;
            $timezone = $this->employeeTimezone($employee);
            $now = $this->clock->now($timezone);
            $isToday = $employee && $this->matchesDate($employee, $now);

            return [
                'today' => (bool) $isToday,
                'date_key' => $now->toDateString(),
                'employees' => $isToday ? [$this->employeePayload($employee, $now)] : [],
            ];
        }

        if (in_array($user->role, ['admin', 'superadmin', 'hr'], true)) {
            $employees = $this->todayBirthdays();
            $appNow = $this->clock->now(config('carevma.business_timezone', 'Asia/Karachi'));

            return [
                'today' => $employees->isNotEmpty(),
                'date_key' => $appNow->toDateString(),
                'employees' => $employees->values()->all(),
            ];
        }

        $timezone = $user->client?->timezone ?: config('carevma.business_timezone', 'Asia/Karachi');
        return [
            'today' => false,
            'date_key' => $this->clock->now($timezone)->toDateString(),
            'employees' => [],
        ];
    }

    public function todayBirthdays(): Collection
    {
        return Employee::query()
            ->whereNotNull('date_of_birth')
            ->get(['id', 'first_name', 'last_name', 'email', 'date_of_birth', 'timezone', 'profile_picture'])
            ->filter(function (Employee $employee) {
                $now = $this->clock->now($this->employeeTimezone($employee));
                return $this->matchesDate($employee, $now);
            })
            ->map(function (Employee $employee) {
                $now = $this->clock->now($this->employeeTimezone($employee));
                return $this->employeePayload($employee, $now);
            })
            ->values();
    }

    /**
     * Create exactly one in-app notification per Admin for every employee whose
     * birthday is tomorrow in that employee's assigned timezone.
     */
    public function sendTomorrowAdminReminders(): int
    {
        $admins = User::query()
            ->whereIn('role', ['admin', 'superadmin'])
            ->get();

        if ($admins->isEmpty()) {
            return 0;
        }

        $employees = Employee::query()
            ->whereNotNull('date_of_birth')
            ->get(['id', 'first_name', 'last_name', 'date_of_birth', 'timezone', 'profile_picture']);

        $created = 0;

        foreach ($employees as $employee) {
            $timezone = $this->employeeTimezone($employee);
            $employeeNow = $this->clock->now($timezone);
            $tomorrow = $employeeNow->copy()->addDay();

            if (!$this->matchesDate($employee, $tomorrow)) {
                continue;
            }

            $birthdayKey = $employee->id.':'.$tomorrow->format('m-d').':'.$tomorrow->year;
            $name = trim($employee->first_name.' '.$employee->last_name) ?: 'Team member';

            foreach ($admins as $admin) {
                $alreadyExists = Notification::query()
                    ->where('user_id', $admin->id)
                    ->where('type', 'BirthdayTomorrow')
                    ->where('created_at', '>=', $this->clock->now('UTC')->copy()->subDays(3))
                    ->get(['id', 'data'])
                    ->contains(fn (Notification $notification) => data_get($notification->data, 'birthday_key') === $birthdayKey);

                if ($alreadyExists) {
                    continue;
                }

                $notification = Notification::create([
                    'user_id' => $admin->id,
                    'type' => 'BirthdayTomorrow',
                    'title' => 'Birthday tomorrow: '.$name,
                    'message' => $name.' has a birthday tomorrow ('.$tomorrow->format('M j').') in '.$timezone.'.',
                    'action_url' => '/admin/dashboard',
                    'data' => [
                        'birthday_key' => $birthdayKey,
                        'employee_id' => $employee->id,
                        'employee_name' => $name,
                        'birthday_date' => $tomorrow->toDateString(),
                        'timezone' => $timezone,
                    ],
                    'is_read' => false,
                ]);

                try {
                    CareVmaNotificationBroadcast::dispatch($notification);
                } catch (\Throwable $e) {
                    Log::warning('Birthday reminder broadcast failed.', [
                        'notification_id' => $notification->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $created++;
            }
        }

        return $created;
    }

    private function employeeTimezone(?Employee $employee): string
    {
        $timezone = trim((string) ($employee?->timezone ?: config('carevma.business_timezone', 'Asia/Karachi')));
        try {
            new \DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable) {
            return config('carevma.business_timezone', 'Asia/Karachi');
        }
    }

    private function matchesDate(Employee $employee, Carbon $date): bool
    {
        if (!$employee->date_of_birth) {
            return false;
        }

        return (int) $employee->date_of_birth->format('m') === (int) $date->format('m')
            && (int) $employee->date_of_birth->format('d') === (int) $date->format('d');
    }

    private function employeePayload(Employee $employee, Carbon $today): array
    {
        return [
            'id' => $employee->id,
            'name' => trim($employee->first_name.' '.$employee->last_name),
            'email' => $employee->email,
            'timezone' => $this->employeeTimezone($employee),
            'profile_picture' => $employee->profile_picture,
            'age' => max(0, $today->year - (int) $employee->date_of_birth->format('Y')),
        ];
    }
}
