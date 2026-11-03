<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Modules;
use App\Models\Attendance;
use App\Models\AttendanceChangeRequest;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeClientSchedule;
use App\Models\Invoice;
use App\Models\NotebookDocument;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * One search box for every portal. Each group is a module the signed-in user
 * can open, and every item carries the SPA path that opens it, pre-filtered
 * where the target page reads ?search= or ?employee_id=.
 *
 * Scope follows the role: HR never sees clients, invoices or HR accounts, a VA
 * only sees their own records, a doctor only the VAs assigned to them.
 */
class SearchApiController extends Controller
{
    private const LIMIT = 5;

    public function __invoke(Request $request)
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }

        $user = $request->user();
        $groups = match ($user->role) {
            'admin', 'superadmin' => $this->management($q, '/admin', $user),
            'hr' => $this->management($q, '/hr', $user),
            'employee' => $this->employee($user, $q),
            'client' => $this->doctor($user, $q),
            default => [],
        };

        return response()->json([
            'groups' => array_values(array_filter($groups, fn (array $group) => $group['items'] !== [])),
        ]);
    }

    private function management(string $q, string $base, User $user): array
    {
        // Admin-only groups keep their /admin links; any staff role granted the module may open them.
        $admin = Modules::allows($user, 'clients') || Modules::allows($user, 'hr-team') || Modules::allows($user, 'invoices');
        $like = $this->like($q);
        $param = '?search='.rawurlencode($q);

        $employees = $this->employeeQuery($like)->with('designation:id,name')->orderBy('first_name')->limit(self::LIMIT)->get();
        $groups = [
            $this->group('Employees', $employees->map(fn (Employee $e) => $this->item(
                $this->name($e),
                implode(' · ', array_filter([$e->employee_code, $e->designation?->name, $e->email])),
                "{$base}/employees/{$e->id}"
            ))),
        ];

        if ($admin) {
            $clients = Client::query()
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('business_name', 'like', $like)->orWhere('email', 'like', $like))
                ->orderBy('name')->limit(self::LIMIT)->get();
            $groups[] = $this->group('Clients', $clients->map(fn (Client $c) => $this->item(
                $c->name, implode(' · ', array_filter([$c->business_name, $c->timezone])), "/admin/client/{$c->id}/profile"
            )));

            $hr = User::query()->where('role', 'hr')
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orderBy('name')->limit(self::LIMIT)->get();
            $groups[] = $this->group('HR Team', $hr->map(fn (User $u) => $this->item(
                $u->name, $u->email, '/admin/hr-team?search='.rawurlencode($u->email)
            )));

            $invoices = Invoice::query()->with('client:id,name,business_name')
                ->where(fn ($w) => $w->where('invoice_number', 'like', $like)
                    ->orWhereHas('client', fn ($c) => $c->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('business_name', 'like', $like))))
                ->latest('id')->limit(self::LIMIT)->get();
            $groups[] = $this->group('Invoices', $invoices->map(fn (Invoice $i) => $this->item(
                'Invoice '.($i->invoice_number ?: "#{$i->id}"),
                implode(' · ', array_filter([$i->client?->name, $this->date($i->period_start).' → '.$this->date($i->period_end), $i->status])),
                "/admin/invoices/{$i->id}/edit"
            )));
        }

        $schedules = EmployeeClientSchedule::query()->with(['employee:id,first_name,last_name', 'client:id,name,business_name'])
            ->where(fn ($w) => $w->whereHas('employee', fn ($e) => $e->where(fn ($x) => $this->nameMatch($x, $like)))
                ->orWhereHas('client', fn ($c) => $c->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('business_name', 'like', $like))))
            ->latest('id')->limit(40)->get()
            ->groupBy(fn (EmployeeClientSchedule $s) => $s->employee_id.'-'.$s->client_id)
            ->take(self::LIMIT);
        // One row per VA/doctor pair: a weekly assignment is five schedules, not five results.
        $groups[] = $this->group('Schedules', $schedules->map(function ($rows) use ($base, $param) {
            $first = $rows->first();
            $days = $rows->map(fn (EmployeeClientSchedule $s) => $s->schedule_date ? $this->date($s->schedule_date) : substr((string) $s->weekday, 0, 3))->unique()->values();
            return $this->item(
                $this->name($first->employee).' → '.($first->client?->name ?: 'Doctor'),
                $rows->count().' shift'.($rows->count() === 1 ? '' : 's').' · '.$days->implode(', ').' · '.$this->time($first->start_time).' – '.$this->time($first->end_time),
                "{$base}/schedules{$param}"
            );
        }));

        $groups[] = $this->group('Attendance', $employees->map(fn (Employee $e) => $this->item(
            $this->name($e).' attendance', 'Attendance log filtered to this VA', "{$base}/attendance?employee_id={$e->id}"
        )));

        $changes = AttendanceChangeRequest::query()->with('employee:id,first_name,last_name')
            ->whereHas('employee', fn ($e) => $e->where(fn ($x) => $this->nameMatch($x, $like)))
            ->latest('id')->limit(self::LIMIT)->get();
        $groups[] = $this->group('Change Requests', $changes->map(fn (AttendanceChangeRequest $r) => $this->item(
            $this->name($r->employee).' · '.$this->date($r->date),
            ucfirst((string) $r->status).' · '.$this->time($r->requested_clock_in).' → '.$this->time($r->requested_clock_out),
            "{$base}/change-requests{$param}"
        )));

        $overtime = Attendance::query()->with('employee:id,first_name,last_name')->where('overtime', '>', 0)
            ->whereHas('employee', fn ($e) => $e->where(fn ($x) => $this->nameMatch($x, $like)))
            ->latest('date')->latest('id')->limit(self::LIMIT)->get();
        $groups[] = $this->group('Overtime', $overtime->map(fn (Attendance $a) => $this->item(
            $this->name($a->employee).' · '.$this->date($a->date),
            (int) $a->overtime.' min · '.ucfirst(str_replace('_', ' ', (string) ($a->overtime_status ?: 'none'))),
            "{$base}/overtime{$param}"
        )));

        $documents = NotebookDocument::query()->with('recipient:id,name')
            ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('original_name', 'like', $like))
            ->latest('id')->limit(self::LIMIT)->get();
        $groups[] = $this->group('Notebook', $documents->map(fn (NotebookDocument $d) => $this->item(
            $d->title ?: (string) $d->original_name,
            implode(' · ', array_filter([$d->recipient?->name ? 'Shared with '.$d->recipient->name : null, $d->original_name])),
            "{$base}/notebook"
        )));

        // Only the modules this role holds are searchable; a group without a module is for all staff.
        $moduleFor = ['Employees' => 'employees', 'Clients' => 'clients', 'HR Team' => 'hr-team', 'Invoices' => 'invoices', 'Schedules' => 'schedules', 'Attendance' => 'attendance', 'Change Requests' => 'change-requests', 'Overtime' => 'overtime', 'Notebook' => 'notebook'];

        return array_values(array_filter($groups, fn (array $group) => !isset($moduleFor[$group['module']]) || Modules::allows($user, $moduleFor[$group['module']])));
    }

    private function employee(User $user, string $q): array
    {
        $employee = $user->employee;
        if (!$employee) {
            return [];
        }
        $like = $this->like($q);
        $param = '?search='.rawurlencode($q);

        $schedules = EmployeeClientSchedule::query()->with('client:id,name,business_name,timezone')
            ->where('employee_id', $employee->id)
            ->where(fn ($w) => $w->where('weekday', 'like', $like)
                ->orWhereHas('client', fn ($c) => $c->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('business_name', 'like', $like))))
            ->latest('id')->limit(self::LIMIT)->get();

        $attendance = Attendance::query()->with('client:id,name,business_name')
            ->where('employee_id', $employee->id)
            ->where(fn ($w) => $w->where('date', 'like', $like)
                ->orWhereHas('client', fn ($c) => $c->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('business_name', 'like', $like))))
            ->latest('date')->latest('id')->limit(self::LIMIT)->get();

        return [
            $this->group('My Schedule', $schedules->map(fn (EmployeeClientSchedule $s) => $this->item(
                ($s->client?->name ?: 'Doctor').' · '.($s->schedule_date ? $this->date($s->schedule_date) : (string) $s->weekday),
                trim($this->time($s->start_time).' – '.$this->time($s->end_time).' '.($s->client?->timezone ?: '')),
                '/employee/schedule'.$param
            ))),
            $this->group('My Attendance', $attendance->map(fn (Attendance $a) => $this->item(
                $this->date($a->date).' · '.($a->client?->name ?: 'Doctor'),
                ((int) $a->total_minutes).' min worked'.((int) $a->late_minutes > 0 ? ' · '.((int) $a->late_minutes).' min late' : ''),
                '/employee/attendance'.$param
            ))),
            $this->group('Shared Documents', $this->sharedDocuments($user, $like)->map(fn (NotebookDocument $d) => $this->item(
                $d->title ?: (string) $d->original_name, $d->original_name, '/employee/documents'.$param
            ))),
        ];
    }

    private function doctor(User $user, string $q): array
    {
        $client = $user->client;
        if (!$client) {
            return [];
        }
        $like = $this->like($q);
        $param = '?search='.rawurlencode($q);

        $assigned = $client->employeeSchedules()->pluck('employee_id')->unique()->values();
        $vas = $this->employeeQuery($like)->whereIn('id', $assigned)->with('designation:id,name')->orderBy('first_name')->limit(self::LIMIT)->get();

        return [
            $this->group('Assigned VAs', $vas->map(fn (Employee $e) => $this->item(
                $this->name($e), $e->designation?->name ?: 'Virtual Assistant', "/doctor/employee/{$e->id}/attendance"
            ))),
            $this->group('Shared Documents', $this->sharedDocuments($user, $like)->map(fn (NotebookDocument $d) => $this->item(
                $d->title ?: (string) $d->original_name, $d->original_name, '/doctor/documents'.$param
            ))),
        ];
    }

    private function sharedDocuments(User $user, string $like)
    {
        return NotebookDocument::query()->where('recipient_user_id', $user->id)
            ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('original_name', 'like', $like))
            ->latest('id')->limit(self::LIMIT)->get();
    }

    private function employeeQuery(string $like)
    {
        return Employee::query()->where(fn ($w) => $this->nameMatch($w, $like)
            ->orWhere('email', 'like', $like)
            ->orWhere('employee_code', 'like', $like));
    }

    private function nameMatch($query, string $like)
    {
        return $query->where('first_name', 'like', $like)
            ->orWhere('last_name', 'like', $like)
            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
    }

    private function like(string $q): string
    {
        return '%'.addcslashes($q, '%_\\').'%';
    }

    private function group(string $module, $items): array
    {
        return ['module' => $module, 'items' => collect($items)->values()->all()];
    }

    private function item(string $label, ?string $meta, string $url): array
    {
        return ['label' => $label, 'meta' => $meta ?: null, 'url' => $url];
    }

    private function name($employee): string
    {
        return trim(($employee?->first_name ?? '').' '.($employee?->last_name ?? '')) ?: 'Employee';
    }

    private function date($value): string
    {
        if (!$value) {
            return '';
        }
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    private function time($value): string
    {
        return substr((string) $value, 0, 5);
    }
}
