<?php

namespace App\Http\Middleware;

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
use Symfony\Component\HttpFoundation\Response;

class OperationalActivityNotifier
{
    public function __construct(private readonly OperationalNotifier $notifier)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        if ($response->getStatusCode() >= 400 || $this->shouldIgnore($request)) {
            return $response;
        }

        $actor = $request->user();
        if (!$actor) {
            return $response;
        }

        $payload = method_exists($response, 'getData') ? (array) $response->getData(true) : [];
        $message = $payload['message'] ?? $this->fallbackMessage($request);
        $title = $this->titleFor($request);
        $recipients = $this->relatedRecipients($request, $payload)
            ->merge($this->notifier->operationsUsers())
            ->push($actor)
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->values();

        // Send per recipient so action links always resolve inside that recipient's own portal.
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
                $users->push($parameter->employee?->user, $parameter->client?->user);
            }
            if ($parameter instanceof Attendance) {
                $users->push($parameter->employee?->user, $parameter->client?->user);
            }
            if ($parameter instanceof AttendanceChangeRequest) {
                $users->push($parameter->employee?->user, $parameter->attendance?->client?->user);
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
                $users->push($schedule?->employee?->user, $schedule?->client?->user);
            }
            if ($key === 'attendance' && !empty($item['id'])) {
                $attendance = Attendance::find($item['id']);
                $users->push($attendance?->employee?->user, $attendance?->client?->user);
            }
            if ($key === 'request' && !empty($item['id'])) {
                $change = AttendanceChangeRequest::find($item['id']);
                $users->push($change?->employee?->user, $change?->attendance?->client?->user);
            }
            if ($key === 'break' && !empty($item['id'])) {
                $break = EmployeeBreak::find($item['id']);
                $users->push($break?->attendance?->employee?->user, $break?->attendance?->client?->user);
            }
        }

        // Creation endpoints can identify related records only by submitted IDs.
        if ($request->filled('employee_id')) $users->push(Employee::find($request->integer('employee_id'))?->user);
        if ($request->filled('client_id') && !str_contains($request->path(), 'invoices')) {
            $users->push(Client::find($request->integer('client_id'))?->user);
        }
        if ($request->filled('schedule_id')) {
            $schedule = EmployeeClientSchedule::find($request->integer('schedule_id'));
            $users->push($schedule?->employee?->user, $schedule?->client?->user);
        }

        return $users->filter(fn ($user) => $user instanceof User)->unique('id')->values();
    }

    private function shouldIgnore(Request $request): bool
    {
        return $request->is(
            'api/logout',
            'api/notifications/read-all',
            'api/notifications/*/read',
            'api/management/notifications/read-all'
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
