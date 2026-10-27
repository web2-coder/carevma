<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\User;
use App\Services\OperationalNotifier;
use App\Services\TrustedTimeService;
use Carbon\Carbon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('carevma:pusher-test {email?}', function (?string $email = null) {
    $user = $email
        ? User::query()->where('email', $email)->first()
        : User::query()->whereIn('role', ['admin', 'superadmin'])->first();

    if (!$user) {
        $this->error('No matching CareVMA user found.');
        return 1;
    }

    app(OperationalNotifier::class)->send(
        $user,
        'Pusher test notification',
        'Real-time CareVMA notifications are connected successfully.',
        'PusherTest',
        $user->role === 'employee' ? '/employee/notifications' : ($user->role === 'client' ? '/doctor/notifications' : ($user->role === 'hr' ? '/hr/notifications' : '/admin/notifications'))
    );

    $this->info('Test notification sent to '.$user->email.'. Keep that user logged in to see the real-time toast.');
    return 0;
})->purpose('Send a CareVMA database/email/Pusher test notification');

/*
|--------------------------------------------------------------------------
| Monthly invoice generation
|--------------------------------------------------------------------------
| Run every minute and let TrustedTimeService decide the real business time.
| This is intentional: a Windows/XAMPP/server wall clock can be wrong while
| the trusted UTC offset is still correct. The command itself is duplicate-safe.
*/
Schedule::command('invoices:generate-monthly')
    ->everyMinute()
    ->name('carevma-monthly-invoice-generation')
    ->withoutOverlapping(120);

/* One-day-before birthday reminder for Admins. */
Schedule::call(function () {
    $clock = app(TrustedTimeService::class);
    $notifier = app(OperationalNotifier::class);
    $admins = User::query()->whereIn('role', ['admin', 'superadmin'])->get();

    if ($admins->isEmpty()) {
        return;
    }

    $employees = Employee::query()
        ->whereNotNull('date_of_birth')
        ->get(['id', 'first_name', 'last_name', 'date_of_birth', 'timezone']);

    foreach ($employees as $employee) {
        $timezone = trim((string) ($employee->timezone ?: config('carevma.business_timezone', 'Asia/Karachi')));
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $timezone = config('carevma.business_timezone', 'Asia/Karachi');
        }

        $tomorrow = $clock->now($timezone)->addDay();
        $birthDate = $employee->date_of_birth;
        if (!$birthDate
            || (int) $birthDate->format('m') !== (int) $tomorrow->format('m')
            || (int) $birthDate->format('d') !== (int) $tomorrow->format('d')) {
            continue;
        }

        $birthdayKey = $employee->id.':'.$tomorrow->toDateString();
        $name = trim($employee->first_name.' '.$employee->last_name);

        $pendingAdmins = $admins->filter(function (User $admin) use ($birthdayKey) {
            return !Notification::query()
                ->where('user_id', $admin->id)
                ->where('type', 'BirthdayTomorrow')
                ->latest('id')
                ->limit(50)
                ->get()
                ->contains(fn (Notification $notification) => ($notification->data['birthday_key'] ?? null) === $birthdayKey);
        })->values();

        if ($pendingAdmins->isEmpty()) {
            continue;
        }

        $notifier->send(
            $pendingAdmins,
            'Birthday tomorrow: '.$name,
            $name.' has a birthday tomorrow in '.$timezone.'.',
            'BirthdayTomorrow',
            '/admin/dashboard',
            [
                'employee_id' => $employee->id,
                'birthday_key' => $birthdayKey,
                'birthday_date' => $tomorrow->toDateString(),
                'timezone' => $timezone,
            ]
        );
    }
})->everyMinute()->when(function () {
    $now = app(TrustedTimeService::class)->now(config('carevma.business_timezone', 'Asia/Karachi'));
    // Send any time after 09:00 on the day-before window. Duplicate protection
    // above guarantees one reminder even though this condition is checked every minute.
    return $now->hour >= 9;
})->name('carevma-birthday-reminders')->withoutOverlapping(30);

/* Existing probation reminder, also tied to the trusted business date. */
Schedule::call(function () {
    $clock = app(TrustedTimeService::class);
    $appNow = $clock->now(config('carevma.business_timezone', 'Asia/Karachi'));

    Log::info('CareVMA probation scheduler running.', ['at' => $appNow->toIso8601String()]);

    $recipients = User::query()
        ->whereIn('role', ['superadmin', 'admin', 'hr'])
        ->get();

    if ($recipients->isEmpty()) {
        Log::warning('CareVMA probation reminder skipped: no Admin/HR recipients exist.');
        return;
    }

    $employees = Employee::query()
        ->where('employment_type_id', '!=', 1)
        ->where('is_probation_email_sent', false)
        ->whereNotNull('probation_end_date')
        ->get();

    foreach ($employees as $employee) {
        $reminderDate = Carbon::parse($employee->probation_end_date)
            ->timezone(config('carevma.business_timezone', 'Asia/Karachi'))
            ->subWeek()
            ->toDateString();

        if ($appNow->toDateString() !== $reminderDate) {
            continue;
        }

        app(OperationalNotifier::class)->send(
            $recipients,
            'Probation ending soon',
            trim($employee->first_name.' '.$employee->last_name).' probation ends on '.Carbon::parse($employee->probation_end_date)->toDateString().'.',
            'ProbationEndingSoon',
            null,
            ['employee_id' => $employee->id]
        );

        $employee->forceFill(['is_probation_email_sent' => true])->save();

        Log::info('CareVMA probation reminder sent.', ['employee_id' => $employee->id]);
    }
})->everyMinute()->when(function () {
    $now = app(TrustedTimeService::class)->now(config('carevma.business_timezone', 'Asia/Karachi'));
    return $now->format('H:i') === '09:15';
})->name('carevma-probation-reminders')->withoutOverlapping(30);
