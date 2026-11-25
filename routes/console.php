<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;
use App\Models\Employee;
use App\Models\Attendance;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\User;
use App\Services\OperationalNotifier;
use App\Services\TrustedTimeService;
use App\Services\AttendanceBillingService;
use App\Services\ClientTimezoneService;
use App\Services\WorkSessionService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

// Retention is opt-in. Default: preserve every audit row until an explicit policy is approved.
Artisan::command('activity-logs:prune {--apply : Delete only when explicitly enabled in config}', function () {
    $days = (int) config('carevma.audit_log_retention_days', 0);
    if ($days < 1) {
        $this->info('Audit log retention disabled; no records deleted.');
        return 0;
    }
    $cutoff = now()->subDays($days);
    $count = ActivityLog::query()->where('created_at', '<', $cutoff)->count();
    if (!$this->option('apply')) {
        $this->warn("DRY RUN: {$count} logs older than {$days} days would be deleted. No changes made.");
        return 0;
    }
    $this->error('Automated deletion is intentionally blocked in this patch. Export/backup and obtain an approved retention policy before enabling destructive pruning.');
    return 1;
})->purpose('Preview activity log retention without deleting historical logs');

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

// Automatic activity-log deletion disabled. Preserve historical records by default.

/* Reconcile one VA work session into Doctor-specific segments every minute. */
Schedule::call(function () {
    app(WorkSessionService::class)->autoCheckoutDueSessions();
})->everyMinute()->name('carevma-work-session-reconcile')->withoutOverlapping(60);

/* Auto-checkout: if a VA forgets to check out, close the session exactly two hours after the Doctor-local scheduled shift end. */
Schedule::call(function () {
    $clock = app(TrustedTimeService::class);
    $timezones = app(ClientTimezoneService::class);
    $billing = app(AttendanceBillingService::class);
    $notifier = app(OperationalNotifier::class);
    $nowUtc = $clock->now('UTC');

    $legacyAttendance = Attendance::query()
        ->with(['employee.user', 'client:id,timezone', 'schedule', 'breaks'])
        ->whereNull('clock_out');
    if (Schema::hasColumn('attendances', 'work_session_id')) {
        $legacyAttendance->whereNull('work_session_id');
    }
    $legacyAttendance->chunkById(100, function ($rows) use ($nowUtc, $timezones, $billing, $notifier) {
            foreach ($rows as $attendance) {
                $deadlineUtc = null;
                if ($attendance->shift_end_snapshot) {
                    $deadlineUtc = $attendance->shift_end_snapshot->copy()->utc()->addHours(2);
                } elseif ($attendance->schedule) {
                    $zone = $timezones->forAttendance($attendance, false);
                    $date = $attendance->date?->toDateString() ?: $attendance->clock_in?->copy()->setTimezone($zone)->toDateString();
                    if ($date) {
                        $start = Carbon::parse($date.' '.$attendance->schedule->start_time, $zone);
                        $end = Carbon::parse($date.' '.$attendance->schedule->end_time, $zone);
                        if ($end->lte($start)) $end->addDay();
                        $deadlineUtc = $end->utc()->addHours(2);
                    }
                }

                if (!$deadlineUtc || $nowUtc->lt($deadlineUtc)) {
                    continue;
                }

                $closed = $billing->finalize($attendance, $deadlineUtc);
                if ($closed->employee?->user) {
                    $notifier->send(
                        $closed->employee->user,
                        'Attendance auto checked out',
                        'Your active session was automatically checked out two hours after the scheduled Doctor-local shift end. Any extra time must be submitted for overtime review before it can be billed.',
                        'AttendanceAutoCheckout',
                        '/employee/attendance',
                        ['attendance_id' => $closed->id]
                    );
                }
            }
        });
})->everyMinute()->name('carevma-auto-checkout')->withoutOverlapping(60);

/* One-day-before birthday reminder for Admins. High-volume safe: pre-filter
| candidates by month/day in SQL and run hourly instead of loading every employee
| every minute. Duplicate protection remains in place. */
Schedule::call(function () {
    $clock = app(TrustedTimeService::class);
    $notifier = app(OperationalNotifier::class);
    $businessZone = config('carevma.business_timezone', 'Asia/Karachi');
    $businessNow = $clock->now($businessZone);
    $admins = User::query()->whereIn('role', ['admin', 'superadmin'])->get();

    if ($admins->isEmpty()) return;

    // World timezones can differ from business time by roughly one calendar day.
    // Three candidate dates keep the query tiny while the per-employee timezone
    // check below preserves exact behavior.
    $candidates = collect([-1, 0, 1])
        ->map(fn (int $offset) => $businessNow->copy()->addDay()->addDays($offset)->format('m-d'))
        ->unique()->values();
    $driver = DB::getDriverName();
    $employees = Employee::query()
        ->whereNotNull('date_of_birth')
        ->where(function ($query) use ($candidates, $driver) {
            foreach ($candidates as $candidate) {
                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    $query->orWhereRaw("DATE_FORMAT(date_of_birth, '%m-%d') = ?", [$candidate]);
                } elseif ($driver === 'sqlite') {
                    $query->orWhereRaw("strftime('%m-%d', date_of_birth) = ?", [$candidate]);
                } elseif ($driver === 'pgsql') {
                    $query->orWhereRaw("TO_CHAR(date_of_birth, 'MM-DD') = ?", [$candidate]);
                } else {
                    $query->orWhereNotNull('date_of_birth');
                }
            }
        })
        ->get(['id', 'first_name', 'last_name', 'date_of_birth', 'timezone']);

    foreach ($employees as $employee) {
        $timezone = trim((string) ($employee->timezone ?: $businessZone));
        try { new \DateTimeZone($timezone); } catch (\Throwable) { $timezone = $businessZone; }

        $tomorrow = $clock->now($timezone)->addDay();
        $birthDate = $employee->date_of_birth;
        if (!$birthDate
            || (int) $birthDate->format('m') !== (int) $tomorrow->format('m')
            || (int) $birthDate->format('d') !== (int) $tomorrow->format('d')) continue;

        $birthdayKey = $employee->id.':'.$tomorrow->toDateString();
        $name = trim($employee->first_name.' '.$employee->last_name);
        $alreadySentTo = Notification::query()
            ->whereIn('user_id', $admins->pluck('id'))
            ->where('type', 'BirthdayTomorrow')
            ->where('created_at', '>=', now()->subDays(2))
            ->get(['user_id', 'data'])
            ->filter(fn (Notification $notification) => ($notification->data['birthday_key'] ?? null) === $birthdayKey)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $pendingAdmins = $admins->reject(fn (User $admin) => in_array((int) $admin->id, $alreadySentTo, true))->values();
        if ($pendingAdmins->isEmpty()) continue;

        $notifier->send(
            $pendingAdmins,
            'Birthday tomorrow: '.$name,
            $name.' has a birthday tomorrow in '.$timezone.'.',
            'BirthdayTomorrow',
            '/admin/dashboard',
            ['employee_id' => $employee->id, 'birthday_key' => $birthdayKey, 'birthday_date' => $tomorrow->toDateString(), 'timezone' => $timezone]
        );
    }
})->hourly()->when(function () {
    return app(TrustedTimeService::class)->now(config('carevma.business_timezone', 'Asia/Karachi'))->hour >= 9;
})->name('carevma-birthday-reminders')->withoutOverlapping(30);

/* Existing probation reminder, with a bounded indexed query. Hourly execution is
| sufficient for a seven-day reminder window and avoids 60 identical scans/hour. */
Schedule::call(function () {
    $clock = app(TrustedTimeService::class);
    $appNow = $clock->now(config('carevma.business_timezone', 'Asia/Karachi'));
    Log::info('CareVMA probation scheduler running.', ['at' => $appNow->toIso8601String()]);

    $recipients = User::query()->whereIn('role', ['superadmin', 'admin', 'hr'])->get();
    if ($recipients->isEmpty()) return;

    $windowStart = $appNow->toDateString();
    $windowEnd = $appNow->copy()->addWeek()->toDateString();
    Employee::query()
        ->where('employment_type_id', '!=', 1)
        ->where('is_probation_email_sent', false)
        ->whereBetween('probation_end_date', [$windowStart, $windowEnd])
        ->orderBy('id')
        ->chunkById(200, function ($employees) use ($recipients) {
            foreach ($employees as $employee) {
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
        });
})->hourly()->when(function () {
    return app(TrustedTimeService::class)->now(config('carevma.business_timezone', 'Asia/Karachi'))->format('H:i') >= '09:15';
})->name('carevma-probation-reminders')->withoutOverlapping(30);


Artisan::command('carevma:mail-diagnostics', function () {
    $mailer = (string) config('mail.default');
    $host = (string) config('mail.mailers.smtp.host');
    $port = (string) config('mail.mailers.smtp.port');
    $queue = (string) config('queue.default');

    $this->info('CareVMA mail diagnostics');
    $this->line('Mailer: '.$mailer);
    $this->line('SMTP host: '.($host ?: '(not configured)'));
    $this->line('SMTP port: '.($port ?: '(not configured)'));
    $this->line('Queue connection: '.($queue ?: '(not configured)'));
    $this->line('From: '.(string) config('mail.from.address'));

    if (str_contains(strtolower($host), 'mailtrap')) {
        $this->warn('Mailtrap detected. Testing plans can reject bursts with 550 5.7.0 rate-limit responses. Use the queued mail worker and production SMTP for live delivery.');
    }

    if ($queue !== 'sync') {
        $this->comment('Queued mail requires a running worker: php artisan queue:work --tries=3 --sleep=1');
    }

    $this->comment('After changing .env mail settings run: php artisan config:clear && php artisan config:cache && php artisan queue:restart');
})->purpose('Show non-secret CareVMA SMTP and queue diagnostics');
