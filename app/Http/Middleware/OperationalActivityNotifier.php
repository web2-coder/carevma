<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\AttendanceChangeRequest;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeBreak;
use App\Models\EmployeeClientSchedule;
use App\Models\Invoice;
use App\Models\User;
use App\Services\OperationalNotifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

use function Illuminate\Support\defer;

class OperationalActivityNotifier
{
    public function __construct(private readonly OperationalNotifier $notifier)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Logout clears the guard before we look, and login fills it only after: read both sides.
        $actorBefore = $request->user();
        $response = $next($request);

        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        $actor = $request->user() ?? $actorBefore;

        // Every mutation lands in the admin activity log, including the sign-ins, sign-outs
        // and ignored endpoints that never notify anyone. always(): failed sign-ins too.
        defer(fn () => $this->record($request, $response, $actor))->always();

        if ($response->getStatusCode() >= 400 || $this->shouldIgnore($request) || !$actor) {
            return $response;
        }

        // Recipient resolution, notification writes, Pusher broadcasts and SMTP all ran
        // inside the request, so a single clock-in blocked on one synchronous
        // Mail::send per admin before the VA saw a response. Defer the whole fan-out:
        // deferred callbacks fire on terminate, after the response is sent, and only
        // when the status was below 400 -- the same condition already guarded above.
        //
        // ponytail: this still occupies the PHP worker that served the request. Switch
        // OperationalNotifier to Mail::queue() once a supervised queue worker is
        // guaranteed in production; QUEUE_CONNECTION is already database, but nothing
        // in the deployment docs promises a running worker.
        defer(function () use ($request, $response, $actor) {
            $payload = method_exists($response, 'getData') ? (array) $response->getData(true) : [];
            $message = $payload['message'] ?? $this->fallbackMessage($request);
            $title = $this->titleFor($request);
            $relatedRecipients = $this->relatedRecipients($request, $payload);

            // Intended-user notifications remain recipient-scoped and never echo back
            // to the actor. Account creation invitations are handled by their dedicated mail flow.
            if ($request->is('api/management/employees') && $request->isMethod('post')) {
                $relatedRecipients = $relatedRecipients->reject(fn ($user) => $user?->role === 'employee');
            }
            if ($request->is('api/admin/clients') && $request->isMethod('post')) {
                $relatedRecipients = $relatedRecipients->reject(fn ($user) => $user?->role === 'client');
            }

            $recipients = $relatedRecipients
                ->filter(fn ($user) => $user instanceof User && $user->id !== $actor->id)
                ->unique('id')
                ->values();

            // Send per intended recipient so action links always resolve inside that
            // recipient's own portal.
            foreach ($recipients as $recipient) {
                $this->notifier->send(
                    $recipient,
                    $title,
                    $actor->name.' ('.$actor->email.') — '.$message,
                    'OperationalActivity',
                    $this->actionUrlForRecipient($request, $recipient),
                    [
                        'actor_id' => $actor->id,
                        'actor_role' => $actor->role,
                        'method' => $request->method(),
                        'path' => '/'.$request->path(),
                    ]
                );
            }

            // Admin + HR get an in-app operational audit notification for every successful
            // business mutation (check-in/out, break actions, profile changes, schedule/data
            // updates, etc.). The actor and already-notified intended recipients are excluded.
            // Audit notifications intentionally do not send email to avoid SMTP bursts.
            $recipientIds = $recipients->pluck('id');
            $operationsRecipients = $this->notifier->operationsUsers()
                ->reject(fn ($user) => $user->id === $actor->id || $recipientIds->contains($user->id))
                ->values();

            foreach ($operationsRecipients as $recipient) {
                $this->notifier->send(
                    $recipient,
                    'Activity: '.$title,
                    $actor->name.' ('.$actor->email.') — '.$message,
                    'OperationalAudit',
                    $this->actionUrlForRecipient($request, $recipient),
                    [
                        'actor_id' => $actor->id,
                        'actor_role' => $actor->role,
                        'method' => $request->method(),
                        'path' => '/'.$request->path(),
                        'audit' => true,
                    ],
                    false
                );
            }

        });
        return $response;
    }

    private function relatedRecipients(Request $request, array $payload): Collection
    {
        $users = collect();

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof User) $users->push($parameter);
            if ($parameter instanceof Employee) $users->push($parameter->user);
            if ($parameter instanceof Client) $users->push($parameter->user);
            if ($parameter instanceof EmployeeClientSchedule) {
                $users->push($parameter->employee?->user);
            }
            if ($parameter instanceof Attendance) {
                $users->push($parameter->employee?->user);
            }
            if ($parameter instanceof AttendanceChangeRequest) {
                $users->push($parameter->employee?->user);
            }
        }

        foreach (['employee', 'client', 'schedule', 'invoice', 'attendance', 'request', 'break', 'user'] as $key) {
            $item = $payload[$key] ?? null;
            if (!is_array($item)) continue;
            if ($key === 'user' && !empty($item['id'])) $users->push(User::find($item['id']));
            if ($key === 'employee' && !empty($item['id'])) $users->push(Employee::find($item['id'])?->user);
            if ($key === 'client' && !empty($item['id'])) $users->push(Client::find($item['id'])?->user);
            if ($key === 'schedule' && !empty($item['id'])) {
                $schedule = EmployeeClientSchedule::find($item['id']);
                $users->push($schedule?->employee?->user);
            }
            if ($key === 'attendance' && !empty($item['id'])) {
                $attendance = Attendance::find($item['id']);
                $users->push($attendance?->employee?->user);
            }
            if ($key === 'request' && !empty($item['id'])) {
                $change = AttendanceChangeRequest::find($item['id']);
                $users->push($change?->employee?->user);
            }
            if ($key === 'break' && !empty($item['id'])) {
                $break = EmployeeBreak::find($item['id']);
                $users->push($break?->attendance?->employee?->user);
            }
        }

        // Creation endpoints can identify related records only by submitted IDs.
        if ($request->filled('employee_id')) $users->push(Employee::find($request->integer('employee_id'))?->user);
        if ($request->filled('client_id') && str_contains($request->path(), 'clients') && !str_contains($request->path(), 'invoices')) {
            $users->push(Client::find($request->integer('client_id'))?->user);
        }
        if ($request->filled('schedule_id')) {
            $schedule = EmployeeClientSchedule::find($request->integer('schedule_id'));
            $users->push($schedule?->employee?->user);
        }

        return $users->filter(fn ($user) => $user instanceof User)->unique('id')->values();
    }

    private function record(Request $request, Response $response, ?User $actor): void
    {
        $status = $response->getStatusCode();
        $isLogin = $request->is('api/login');
        if (($status >= 400 && !$isLogin) || (!$actor && !$isLogin)) {
            return;
        }

        try {
            $payload = method_exists($response, 'getData') ? (array) $response->getData(true) : [];
            $subject = $actor ? $this->relatedRecipients($request, $payload)->first(fn (User $user) => $user->id !== $actor->id) : null;

            ActivityLog::create([
                'user_id' => $actor?->id,
                'actor_name' => $actor?->name,
                'actor_email' => $actor?->email ?? mb_substr((string) $request->input('email'), 0, 190),
                'actor_role' => $actor?->role ?? 'guest',
                'action' => $this->actionFor($request, $status),
                'module' => $this->moduleFor($request),
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'status_code' => $status,
                'description' => isset($payload['message']) ? mb_substr((string) $payload['message'], 0, 1000) : null,
                'subject_user_id' => $subject?->id,
                'subject_name' => $subject?->name,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]);
        } catch (\Throwable $e) {
            Log::warning('CareVMA activity log could not be saved.', ['path' => $request->path(), 'error' => $e->getMessage()]);
        }
    }

    private function actionFor(Request $request, int $status): string
    {
        if ($request->is('api/login')) {
            return $status < 400 ? 'Signed in' : 'Sign-in failed';
        }
        if ($request->is('api/logout')) {
            return 'Signed out';
        }
        return $this->titleFor($request);
    }

    /** Module names match the sidebar; order matters because paths share words (employee/clock-in). */
    private function moduleFor(Request $request): string
    {
        $path = $request->path();
        $modules = [
            'Account' => ['login', 'logout', 'password', 'profile', 'account', 'google', 'settings'],
            'Change requests' => ['change-request'],
            'Attendance' => ['attendance', 'clock', 'break', 'work-session'],
            'Overtime' => ['overtime'],
            'Schedules' => ['schedule'],
            'Invoices' => ['invoice'],
            'Finance' => ['finance'],
            'Documents' => ['notebook', 'document'],
            'HR team' => ['hr-user'],
            'Employees' => ['employee'],
            'Clients' => ['client'],
            'Notifications' => ['notification', 'birthday'],
        ];
        foreach ($modules as $module => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($path, $needle)) {
                    return $module;
                }
            }
        }
        return 'Configuration';
    }

    private function shouldIgnore(Request $request): bool
    {
        if (($request->is('api/admin/hr-users') && $request->isMethod('post'))
            || $request->is('api/management/employees/*/resend-invite')
            || $request->is('api/admin/clients/*/resend-invite')) {
            return true;
        }

        return $request->is(
            'api/login',
            'api/logout',
            'api/notifications/read-all',
            'api/notifications/*/read',
            'api/management/notifications/read-all',
            'api/management/schedules/batch',
            'api/birthday-popup/seen',
            'api/admin/invoices/*/send',
            'api/employee/birthday-wishes/*'
        );
    }

    private function fallbackMessage(Request $request): string
    {
        return match ($request->method()) {
            'POST' => 'A CareVMA action was completed successfully.',
            'PUT', 'PATCH' => 'A CareVMA record was updated successfully.',
            'DELETE' => 'A CareVMA record was deleted successfully.',
            default => 'A CareVMA action was completed.',
        };
    }

    private function titleFor(Request $request): string
    {
        $path = str_replace(['api/', 'management/', 'admin/', 'employee/', 'doctor/'], '', $request->path());
        $label = trim(str_replace(['-', '/', '_'], ' ', preg_replace('/\d+/', '', $path)));
        return $label ? ucwords($label).' updated' : 'CareVMA activity';
    }

    private function actionUrlForRecipient(Request $request, User $recipient): ?string
    {
        $path = $request->path();
        $role = $recipient->role;

        if (str_contains($path, 'invoices')) {
            return in_array($role, ['admin', 'superadmin'], true) ? '/admin/invoices' : null;
        }

        if (str_contains($path, 'change-requests')) {
            return match ($role) {
                'employee' => '/employee/attendance',
                'hr' => '/hr/change-requests',
                'admin', 'superadmin' => '/admin/change-requests',
                default => null,
            };
        }

        if (str_contains($path, 'overtime')) {
            return match ($role) {
                'employee' => '/employee/attendance',
                'hr' => '/hr/overtime',
                'admin', 'superadmin' => '/admin/overtime',
                default => null,
            };
        }

        if (str_contains($path, 'schedules')) {
            return match ($role) {
                'employee' => '/employee/schedule',
                'client' => '/doctor/dashboard',
                'hr' => '/hr/schedules',
                'admin', 'superadmin' => '/admin/schedules',
                default => null,
            };
        }

        if (str_contains($path, 'attendance') || str_contains($path, 'clock-') || str_contains($path, 'break/')) {
            return match ($role) {
                'employee' => '/employee/attendance',
                'client' => '/doctor/dashboard',
                'hr' => '/hr/attendance',
                'admin', 'superadmin' => '/admin/attendance',
                default => null,
            };
        }

        if (str_contains($path, 'employees')) {
            return match ($role) {
                'employee' => '/employee/profile',
                'client' => '/doctor/dashboard',
                'hr' => '/hr/employees',
                'admin', 'superadmin' => '/admin/employees',
                default => null,
            };
        }

        if (str_contains($path, 'clients')) {
            return match ($role) {
                'client' => '/doctor/profile',
                'admin', 'superadmin' => '/admin/clients',
                default => null,
            };
        }

        if (str_contains($path, 'profile') || str_contains($path, 'account') || str_contains($path, 'password')) {
            return match ($role) {
                'employee' => '/employee/profile',
                'client' => '/doctor/profile',
                'hr' => '/hr/account',
                'admin', 'superadmin' => '/admin/account',
                default => null,
            };
        }

        return null;
    }
}
