<?php

namespace App\Http\Controllers\Api;
use App\Services\Modules;
use App\Services\BillingCycle;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceChangeRequest;
use App\Models\Client;
use App\Models\ClientEmployeeRate;
use App\Models\CompanyFinanceEntry;
use App\Models\ContractType;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeClientSchedule;
use App\Models\ScheduleChangeRequest;
use App\Models\WorkSession;
use App\Models\EmployeeSalary;
use App\Models\EmployeeStatus;
use App\Models\EmploymentType;
use App\Models\Expertise;
use App\Models\Invoice;
use App\Models\ReportingManager;
use App\Models\Role;
use App\Models\Service;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\InvoiceGenerator;
use App\Services\InvoiceAutoGenerationSchedule;
use App\Services\FxRateService;
use App\Services\TrustedTimeService;
use App\Services\ClientTimezoneService;
use App\Services\AttendanceBillingService;
use App\Services\WorkSessionService;
use App\Services\InvoicePdfService;
use App\Services\InvoiceDraftSyncService;
use App\Services\InvoiceSnapshotService;
use App\Services\OperationalNotifier;
use DateTimeZone;
use App\Mail\EmployeeInvitationMail;
use App\Mail\ClientSetPasswordMail;
use App\Mail\HrInvitationMail;
use App\Mail\InvoiceNotificationMail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminApiController extends Controller
{
    public function __construct(
        private TrustedTimeService $clock,
        private ClientTimezoneService $timezones,
        private AttendanceBillingService $attendanceBilling,
        private WorkSessionService $workSessions,
        private InvoiceAutoGenerationSchedule $invoiceAutoSchedule,
        private InvoiceDraftSyncService $invoiceDraftSync,
        private InvoiceSnapshotService $invoiceSnapshots,
    ) {
    }

    private array $referenceMap = [
        'departments' => Department::class,
        'roles' => Role::class,
        'employment-types' => EmploymentType::class,
        'shift-types' => ShiftType::class,
        'designations' => Designation::class,
        'employee-statuses' => EmployeeStatus::class,
        'expertises' => Expertise::class,
        'reporting-managers' => ReportingManager::class,
        'services' => Service::class,
        'contracts' => ContractType::class,
    ];

    private function attendanceSummariesForClientLocalDates(array $localDates): array
    {
        $dates = collect($localDates)->filter()->unique()->sort()->values();
        $result = $dates->mapWithKeys(fn ($date) => [$date => ['sessions' => 0, 'minutes' => 0]])->all();
        if ($dates->isEmpty()) return $result;

        $minDate = $dates->first();
        $maxDate = $dates->last();
        $scanStart = Carbon::parse($minDate, 'UTC')->subDays(2)->startOfDay();
        $scanEnd = Carbon::parse($maxDate, 'UTC')->addDays(2)->endOfDay();
        $businessZone = $this->timezones->businessTimezone();

        // Join only the clients referenced by attendance rows instead of hydrating
        // the entire Doctor table (50k+ rows on load-test databases).
        DB::table('attendances')
            ->join('clients', 'clients.id', '=', 'attendances.client_id')
            ->select(['attendances.id', 'attendances.clock_in', 'attendances.total_minutes', 'clients.timezone as client_timezone'])
            ->whereNotNull('attendances.client_id')
            ->whereBetween('attendances.clock_in', [$scanStart, $scanEnd])
            ->orderBy('attendances.id')
            ->cursor()
            ->each(function ($row) use (&$result, $businessZone) {
                if (!$row->clock_in) return;
                $zone = $row->client_timezone ?: $businessZone;
                try {
                    $localDate = Carbon::parse((string) $row->clock_in, 'UTC')->setTimezone($zone)->toDateString();
                } catch (\Throwable) {
                    $localDate = Carbon::parse((string) $row->clock_in, 'UTC')->setTimezone($businessZone)->toDateString();
                }
                if (!isset($result[$localDate])) return;
                $result[$localDate]['sessions']++;
                $result[$localDate]['minutes'] += (int) ($row->total_minutes ?? 0);
            });

        $legacyRows = Attendance::query()
            ->selectRaw('date, COUNT(*) as sessions, COALESCE(SUM(total_minutes),0) as minutes')
            ->whereNull('client_id')
            ->whereBetween('date', [$minDate, $maxDate])
            ->groupBy('date')
            ->get();
        foreach ($legacyRows as $row) {
            $key = optional($row->date)->toDateString() ?: (string) $row->date;
            if (!isset($result[$key])) continue;
            $result[$key]['sessions'] += (int) $row->sessions;
            $result[$key]['minutes'] += (int) $row->minutes;
        }

        return $result;
    }

    public function dashboard(Request $request)
    {
        $appNow = $this->clock->now($this->timezones->businessTimezone());
        $today = $appNow->toDateString();
        $weekStart = $appNow->copy()->startOfWeek();
        $weekDates = collect(range(0, 6))->map(fn ($offset) => $weekStart->copy()->addDays($offset)->toDateString());
        $attendanceSummaries = $this->attendanceSummariesForClientLocalDates($weekDates->all());
        $trend = $weekDates->map(function ($date) use ($attendanceSummaries) {
            $point = Carbon::parse($date, $this->timezones->businessTimezone());
            $summary = $attendanceSummaries[$date] ?? ['sessions' => 0, 'minutes' => 0];
            return [
                'label' => $point->format('D'),
                'date' => $date,
                'sessions' => $summary['sessions'],
                'minutes' => $summary['minutes'],
            ];
        });

        // Dashboard only needs a count plus the latest three sessions. Never hydrate
        // every open session or reconcile them during a dashboard read; the minute
        // scheduler already performs reconciliation/auto-checkout.
        if ($this->workSessions->supported()) {
            $checkedInCount = WorkSession::query()->whereNull('clock_out')->count();
            $currentlyWorking = WorkSession::query()
                ->with([
                    'employee.user:id,name,email,last_seen_at',
                    'attendances.client:id,name,business_name,timezone,profile_picture,profile_picture',
                    'attendances.schedule:id,weekday,start_time,end_time,timezone,break_allowed_hours',
                    'attendances.breaks:id,attendance_id,start_time,end_time',
                    'breaks:id,work_session_id,start_time,end_time',
                ])
                ->whereNull('clock_out')
                ->latest('clock_in')
                ->limit(3)
                ->get()
                ->map(fn (WorkSession $session) => $this->mapLiveWorkSession($session, $this->clock->now('UTC')))
                ->values();
        } else {
            $checkedInCount = Attendance::query()->whereNull('clock_out')->count();
            $currentlyWorking = Attendance::query()
                ->with(['employee:id,first_name,last_name,employee_code,profile_picture,timezone','client:id,name,business_name,timezone,profile_picture,profile_picture'])
                ->whereNull('clock_out')->latest('clock_in')->limit(3)->get()->map(function (Attendance $attendance) {
                    $doctorName = $attendance->client?->name ?: $attendance->client?->business_name;
                    return [
                        'id'=>$attendance->id,
                        'employee'=>$attendance->employee,
                        'client'=>$attendance->client,
                        'doctor_names'=>collect([$doctorName])->filter()->values(),
                        'doctor_segments'=>collect([[
                            'id'=>$attendance->id,
                            'name'=>$doctorName ?: 'Doctor',
                            'timezone'=>$this->timezones->forAttendance($attendance, true),
                            'shift_ends_at'=>$attendance->shift_end_snapshot?->copy()->utc()->toIso8601ZuluString(),
                        ]]),
                        'clock_in'=>$attendance->clock_in?->toIso8601String(),
                    ];
                })->values();
        }
        // Match the Overtime Review list: zero-minute rows cannot be reviewed.
        // Older/test attendance can retain a pending status even with no overtime.
        $pendingOvertime = Attendance::query()
            ->where('overtime', '>', 0)
            ->whereIn('overtime_status', ['pending', 'review_required'])
            ->count();

        $employeeTimezoneCounts = Employee::query()
            ->whereNotNull('timezone')
            ->where('timezone', '!=', '')
            ->selectRaw('timezone, COUNT(*) as total')
            ->groupBy('timezone')
            ->pluck('total', 'timezone');

        $clientTimezoneCounts = Client::query()
            ->whereNotNull('timezone')
            ->where('timezone', '!=', '')
            ->selectRaw('timezone, COUNT(*) as total')
            ->groupBy('timezone')
            ->pluck('total', 'timezone');

        $timezoneClocks = $employeeTimezoneCounts->keys()
            ->merge($clientTimezoneCounts->keys())
            ->map(fn ($timezone) => $this->validTimezoneValue((string) $timezone, $this->timezones->businessTimezone()))
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $timezone) use ($employeeTimezoneCounts, $clientTimezoneCounts) {
                return [
                    'timezone' => $timezone,
                    'va_count' => (int) ($employeeTimezoneCounts[$timezone] ?? 0),
                    'doctor_count' => (int) ($clientTimezoneCounts[$timezone] ?? 0),
                ];
            });

        // Do not hydrate every employee just to discover today's birthdays. Across
        // world timezones the local calendar date can differ from business time by at
        // most one day, so pre-filter to those three month/day candidates in SQL and
        // then keep the existing exact per-employee timezone check below.
        $birthdayCandidates = collect([-1, 0, 1])
            ->map(fn (int $offset) => $appNow->copy()->addDays($offset)->format('m-d'))
            ->unique()
            ->values();
        $birthdayDriver = DB::getDriverName();
        $birthdaysToday = Employee::query()
            ->whereNotNull('date_of_birth')
            ->where(function ($query) use ($birthdayCandidates, $birthdayDriver) {
                foreach ($birthdayCandidates as $candidate) {
                    if (in_array($birthdayDriver, ['mysql', 'mariadb'], true)) {
                        $query->orWhereRaw("DATE_FORMAT(date_of_birth, '%m-%d') = ?", [$candidate]);
                    } elseif ($birthdayDriver === 'sqlite') {
                        $query->orWhereRaw("strftime('%m-%d', date_of_birth) = ?", [$candidate]);
                    } elseif ($birthdayDriver === 'pgsql') {
                        $query->orWhereRaw("TO_CHAR(date_of_birth, 'MM-DD') = ?", [$candidate]);
                    } else {
                        // Unknown driver: keep correctness over optimization.
                        $query->orWhereNotNull('date_of_birth');
                    }
                }
            })
            ->get(['id', 'first_name', 'last_name', 'date_of_birth', 'timezone', 'profile_picture'])
            ->filter(function (Employee $employee) {
                $timezone = $this->validTimezoneValue($employee->timezone ?: $this->timezones->businessTimezone(), $this->timezones->businessTimezone());
                $localToday = $this->clock->now($timezone);
                return (int) $employee->date_of_birth->format('m') === (int) $localToday->format('m')
                    && (int) $employee->date_of_birth->format('d') === (int) $localToday->format('d');
            })
            ->map(function (Employee $employee) {
                $timezone = $this->validTimezoneValue($employee->timezone ?: $this->timezones->businessTimezone(), $this->timezones->businessTimezone());
                return [
                    'id' => $employee->id,
                    'name' => trim($employee->first_name.' '.$employee->last_name),
                    'timezone' => $timezone,
                    'profile_picture' => $employee->profile_picture,
                ];
            })
            ->values();

        // The billing cycle open right now, per Invoice Settings; drafts are generated once it
        // closes, at the configured time in each client/doctor timezone.
        $billingSettings = BillingCycle::settings();
        $openCycleStart = BillingCycle::openStart($appNow);
        $cycleStart = $openCycleStart->toDateString();
        $cycleEnd = BillingCycle::endOf($openCycleStart)->toDateString();
        $scheduledClose = $this->invoiceAutoSchedule->dueAt($cycleEnd);
        $cycleEndOpenAttendance = $this->invoiceAutoSchedule->openAttendanceCount($cycleEnd);

        $cycleInvoices = Invoice::query()
            ->where('auto_generated', true)
            ->where('billing_cycle_month', Carbon::parse($cycleEnd)->startOfMonth()->toDateString())
            ->whereDate('period_end', $cycleEnd);
        $cycleCount = (clone $cycleInvoices)->count();
        $cycleAmount = (float) (clone $cycleInvoices)->sum('total_amount');
        $cycleVaCount = $cycleCount === 0 ? 0 : EmployeeSalary::query()
            ->whereIn('invoice_id', (clone $cycleInvoices)->select('id'))
            ->distinct()
            ->count('employee_id');
        $lastGeneratedAt = (clone $cycleInvoices)->max('generated_at');
        $lastCompletedCycle = null;

        // Once this cycle has generated automatic drafts, keep its totals as the
        // previous run summary but move the dashboard focus to the next cycle.
        if ($cycleCount > 0) {
            $lastCompletedCycle = [
                'period_start' => $cycleStart,
                'period_end' => $cycleEnd,
                'generated_count' => $cycleCount,
                'billed_va_count' => $cycleVaCount,
                'total_amount' => round($cycleAmount, 2),
                'last_generated_at' => $lastGeneratedAt,
            ];

            $openCycleStart = Carbon::parse($cycleEnd, $this->timezones->businessTimezone())->addDay()->startOfDay();
            $cycleStart = $openCycleStart->toDateString();
            $cycleEnd = BillingCycle::endOf($openCycleStart)->toDateString();
            $scheduledClose = $this->invoiceAutoSchedule->dueAt($cycleEnd);
            $cycleEndOpenAttendance = $this->invoiceAutoSchedule->openAttendanceCount($cycleEnd);

            $cycleInvoices = Invoice::query()
                ->where('auto_generated', true)
                ->where('billing_cycle_month', Carbon::parse($cycleEnd)->startOfMonth()->toDateString())
                ->whereDate('period_end', $cycleEnd);
            $cycleCount = (clone $cycleInvoices)->count();
            $cycleAmount = (float) (clone $cycleInvoices)->sum('total_amount');
            $cycleVaCount = $cycleCount === 0 ? 0 : EmployeeSalary::query()
                ->whereIn('invoice_id', (clone $cycleInvoices)->select('id'))
                ->distinct()
                ->count('employee_id');
            $lastGeneratedAt = (clone $cycleInvoices)->max('generated_at');
        }

        $canViewFinance = Modules::allows($request->user(), 'company-finance');
        $canViewInvoices = Modules::allows($request->user(), 'invoices');
        $financeRangeStart = $appNow->copy()->subMonthsNoOverflow(5)->startOfMonth();
        $financeRangeEnd = $appNow->copy()->endOfMonth();
        $financeDaily = $canViewFinance ? $this->financeDailySeries($financeRangeStart, $financeRangeEnd) : [];
        $financeTrend = collect(range(5, 0))->map(function ($offset) use ($appNow, $financeDaily) {
            $month = $appNow->copy()->subMonthsNoOverflow($offset);
            $snapshot = $this->financeSummaryFromDaily($financeDaily, $month->copy()->startOfMonth(), $month->copy()->endOfMonth());
            return [
                'label' => $month->format('M'),
                'income' => $snapshot['incoming'],
                'outgoing' => $snapshot['outgoing'],
                'net' => $snapshot['net'],
            ];
        });
        $finance = $this->financeSummaryFromDaily($financeDaily, $appNow->copy()->startOfMonth(), $appNow->copy()->endOfMonth());

        return response()->json([
            'server_now' => $this->clock->now('UTC')->toIso8601ZuluString(),
            'metrics' => [
                'employees' => Employee::count(),
                'clients' => Client::count(),
                'active' => $checkedInCount,
                'today_sessions' => ($attendanceSummaries[$today]['sessions'] ?? 0),
                'pending_overtime' => $pendingOvertime,
                'invoices' => $canViewInvoices ? Invoice::count() : null,
            ],
            'trend' => $trend,
            'recent_attendance' => Attendance::with([
                'employee:id,first_name,last_name,profile_picture,timezone',
                'client:id,name,business_name,timezone,profile_picture',
            ])->latest('id')->limit(5)->get()->map(function (Attendance $row) {
                $clientZone = $this->timezones->forAttendance($row, true);
                $row->setAttribute('client_timezone', $clientZone);
                $row->setAttribute('client_local_date', $row->clock_in
                    ? $this->timezones->localDate($row->clock_in, $clientZone)
                    : optional($row->date)->toDateString());
                $row->setAttribute('clock_in_client', $this->timezones->isoInZone($row->clock_in, $clientZone));
                $row->setAttribute('clock_out_client', $this->timezones->isoInZone($row->clock_out, $clientZone));
                return $row;
            }),
            'birthdays_today' => $birthdaysToday,
            'timezone_clocks' => $timezoneClocks,
            'currently_working' => $currentlyWorking,
            'auto_invoice_cycle' => $canViewInvoices ? [
                'period_start' => $cycleStart,
                'period_end' => $cycleEnd,
                'scheduled_for' => $scheduledClose->toIso8601String(),
                'generated_count' => $cycleCount,
                'billed_va_count' => $cycleVaCount,
                'total_amount' => round($cycleAmount, 2),
                'last_generated_at' => $lastGeneratedAt,
                'status' => $cycleCount > 0 ? 'generated' : (!$billingSettings['auto_generate'] ? 'disabled' : ($this->clock->now('UTC')->lt($scheduledClose) ? 'scheduled' : ($cycleEndOpenAttendance > 0 ? 'waiting_for_checkout' : 'ready'))),
                'cycle_start_day' => $billingSettings['cycle_start_day'],
                'generation_time' => $billingSettings['generation_time'],
                'auto_generate' => $billingSettings['auto_generate'],
                'open_cycle_end_attendance' => $cycleEndOpenAttendance,
                'generation_rule' => 'after_final_cycle_end_shift',
                'admin_timezone' => $this->timezones->businessTimezone(),
                'doctor_runs' => [],
                'doctor_runs_total' => Client::query()->count(),
                'last_completed_cycle' => $lastCompletedCycle,
            ] : null,
            'finance' => $canViewFinance ? $finance : null,
            'finance_trend' => $canViewFinance ? $financeTrend : [],
        ]);
    }


    public function invoiceRunDetails(Request $request)
    {
        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $perPage = min(max((int) ($data['per_page'] ?? 25), 1), 50);
        $query = Client::query()->select(['id', 'name', 'business_name', 'timezone']);
        if ($search = trim((string) ($data['search'] ?? ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search.'%')
                    ->orWhere('business_name', 'like', $search.'%')
                    ->orWhere('email', 'like', $search.'%');
            });
        }
        $paginator = $query->orderBy('name')->orderBy('id')->paginate($perPage);
        $rows = $this->invoiceAutoSchedule->doctorRunDetailsForClients(
            $paginator->getCollection(),
            Carbon::parse($data['period_start'])->toDateString(),
            Carbon::parse($data['period_end'])->toDateString(),
            $this->clock->now('UTC')
        );

        return response()->json([
            'data' => $rows,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ]);
    }

    public function liveCount(Request $request)
    {
        $total = $this->workSessions->supported() ? WorkSession::query()->whereNull('clock_out')->count() : Attendance::query()->whereNull('clock_out')->count();
        return response()->json(['total' => $total]);
    }

    public function liveUsers(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['checked_in', 'break', 'late', 'offline'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        if ($this->workSessions->supported()) {
            try {
                return $this->liveUsersFromWorkSessions($request);
            } catch (\Throwable $e) {
                Log::error('Live Workforce work-session feed failed.', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                return response()->json(['message' => 'Live workforce data could not be loaded.'], 500);
            }
        }

        return response()->json(['message' => 'Work sessions are not available.'], 503);
    }

    public function employees(Request $request)
    {
        $query = Employee::with(['user:id,name,email,role,is_active,last_seen_at', 'department', 'designation', 'employmentType', 'shiftType', 'employeeStatus', 'expertise', 'reportingManager']);
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%");
            });
        }
        foreach (['department_id', 'designation_id', 'employee_status_id'] as $column) {
            if ($request->filled($column)) $query->where($column, $request->integer($column));
        }
        if ($request->filled('timezone')) $query->where('timezone', (string) $request->query('timezone'));
        return response()->json($this->sortAndPaginate($request, $query, [
            'id' => 'id', 'employee_code' => 'employee_code', 'first_name' => 'first_name', 'last_name' => 'last_name',
            'email' => 'email', 'timezone' => 'timezone', 'salary_usd' => 'salary_usd', 'created_at' => 'created_at',
        ], 'id'));
    }

    public function employee(Employee $employee)
    {
        $employee->load(['user:id,name,email,role,is_active,last_seen_at', 'department', 'role', 'employmentType', 'shiftType', 'designation', 'employeeStatus', 'expertise', 'reportingManager']);
        $employee->setAttribute('upload_documents', $this->employeeDocumentUrls($employee));

        // "This month" in the VA's own timezone, so the figures match what they see.
        $timezone = in_array($employee->timezone, DateTimeZone::listIdentifiers(), true) ? $employee->timezone : config('app.timezone');
        $today = Carbon::now($timezone);
        $month = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()])
            ->selectRaw("COUNT(*) AS sessions, COALESCE(SUM(total_minutes), 0) AS minutes, COALESCE(SUM(late_minutes), 0) AS late_minutes, COALESCE(SUM(overtime), 0) AS overtime_minutes, COALESCE(SUM(late_minutes > 0), 0) AS late_sessions, COALESCE(SUM(CASE WHEN overtime_status IN ('pending', 'review_required') THEN overtime ELSE 0 END), 0) AS pending_overtime_minutes")
            ->first();
        $employee->setAttribute('month_summary', [
            'sessions' => (int) $month->sessions,
            'minutes' => (int) $month->minutes,
            'late_minutes' => (int) $month->late_minutes,
            'overtime_minutes' => (int) $month->overtime_minutes,
            'late_sessions' => (int) $month->late_sessions,
            'pending_overtime_minutes' => (int) $month->pending_overtime_minutes,
        ]);
        $employee->setAttribute('is_live', Attendance::query()->where('employee_id', $employee->id)->whereNull('clock_out')->exists());
        $employee->setAttribute('probation', $employee->probationStatusData($today));

        return response()->json($employee);
    }

    public function storeEmployee(Request $request)
    {
        $data = $this->validateEmployee($request);
        if (empty($data['probation_end_date']) && !empty($data['date_of_joining'])) {
            $data['probation_end_date'] = Carbon::parse($data['date_of_joining'])->addMonthsNoOverflow(3)->toDateString();
        }

        [$employee, $user] = DB::transaction(function () use ($data, $request) {
            // Admin/HR never needs to know the VA password. The account receives an
            // unguessable temporary password and the VA chooses their own password
            // through the secure activation link sent after creation.
            $temporaryPassword = Str::random(64);
            $user = User::create([
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'email' => $data['email'],
                'password' => Hash::make($temporaryPassword),
                'role' => 'employee',
            ]);

            $media = $this->storeEmployeeMedia($request);
            $employee = Employee::create(array_merge($this->employeePayload($data), $media, [
                'user_id' => $user->id,
                'password' => $user->password,
                'profile_completed' => 0,
            ]));

            return [$employee, $user];
        });

        $invite = $this->sendEmployeeInvitation($user, $employee);

        $freshEmployee = $employee->fresh();
        $freshEmployee->setAttribute('upload_documents', $this->employeeDocumentUrls($freshEmployee));

        return response()->json([
            'message' => $invite['sent']
                ? 'VA created and activation invitation sent.'
                : 'VA created. Email delivery failed, so use the activation link below after checking SMTP settings.',
            'employee' => $freshEmployee,
            'invitation_sent' => $invite['sent'],
            'activation_url' => $invite['url'],
        ], 201);
    }

    public function resendEmployeeInvitation(Employee $employee)
    {
        $employee->loadMissing('user');
        abort_unless($employee->user, 422, 'This employee does not have a login account.');

        $invite = $this->sendEmployeeInvitation($employee->user, $employee);

        return response()->json([
            'message' => $invite['sent']
                ? 'Activation invitation sent again.'
                : 'Email delivery failed. The activation link is available below.',
            'invitation_sent' => $invite['sent'],
            'activation_url' => $invite['url'],
        ]);
    }

    public function updateEmployee(Request $request, Employee $employee)
    {
        $removePicture = $request->boolean('remove_profile_picture') && !$request->hasFile('profile_picture');
        $data = $this->validateEmployee($request, $employee, true);
        $previousPicture = $employee->profile_picture;
        $newPicture = null;
        if ($request->hasFile('profile_picture')) {
            $newPicture = '/storage/'.$request->file('profile_picture')->store('employees/profile_pictures', 'public');
        }
        $response = DB::transaction(function () use ($data, $employee, $removePicture, $newPicture) {
            $payload = $this->employeePayload($data);
            if ($newPicture) $payload['profile_picture'] = $newPicture;
            elseif ($removePicture) $payload['profile_picture'] = null;
            $employee->update($payload);
            $userData = ['name' => trim($employee->first_name.' '.$employee->last_name), 'email' => $employee->email];
            if (!empty($data['password'])) {
                $userData['password'] = Hash::make($data['password']);
                $employee->update(['password' => $userData['password']]);
            }
            $employee->user?->update($userData);
            $freshEmployee = $employee->fresh();
            $freshEmployee->setAttribute('upload_documents', $this->employeeDocumentUrls($freshEmployee));
            return response()->json(['message' => 'Employee updated.', 'employee' => $freshEmployee]);
        });
        if (($newPicture || $removePicture) && $previousPicture) {
            \App\Support\ProfilePhotoStorage::deleteOld($previousPicture, 'employees/profile_pictures');
        }
        return $response;
    }

    public function updateProbation(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['end_now', 'extend'])],
            'end_date' => ['nullable', 'date'],
        ]);

        $today = $this->clock->now(config('carevma.business_timezone', 'Asia/Karachi'))->startOfDay();
        $employee->loadMissing('user');

        if ($data['action'] === 'extend') {
            if (empty($data['end_date'])) {
                throw ValidationException::withMessages(['end_date' => ['Choose the new probation end date.']]);
            }
            $end = Carbon::parse($data['end_date'])->startOfDay();
            if ($end->lte($today)) {
                throw ValidationException::withMessages(['end_date' => ['The extended probation end date must be after today.']]);
            }
            if ($employee->date_of_regularisation && Carbon::parse($employee->date_of_regularisation)->lte($today)) {
                throw ValidationException::withMessages(['end_date' => ['This employee is already permanent.']]);
            }

            $employee->forceFill([
                'probation_end_date' => $end->toDateString(),
                'is_probation_email_sent' => false,
            ])->save();

            if ($employee->user) {
                app(OperationalNotifier::class)->send(
                    $employee->user,
                    'Probation period updated',
                    'Your probation period has been extended until '.$end->format('d M Y').'.',
                    'ProbationExtended',
                    '/employee/profile',
                    ['employee_id' => $employee->id, 'probation_end_date' => $end->toDateString()]
                );
            }

            return response()->json([
                'message' => 'Probation extended successfully.',
                'employee' => $employee->fresh(),
                'probation' => $employee->fresh()->probationStatusData($today),
            ]);
        }

        $permanentTypeId = EmploymentType::query()
            ->whereRaw('LOWER(name) = ?', ['permanent'])
            ->value('id');

        $employee->forceFill(array_filter([
            'date_of_regularisation' => $today->toDateString(),
            // Ending probation now must close the active probation period today. Keeping
            // an old future date can make profile/status UIs look like probation is active.
            'probation_end_date' => $today->toDateString(),
            'employment_type_id' => $permanentTypeId ?: $employee->employment_type_id,
            'is_probation_email_sent' => true,
        ], fn ($value) => $value !== null))->save();

        // Reload the employment type before deriving the response status so the same API
        // response already reports Permanent without requiring another request/reload.
        $employee->refresh()->load('employmentType');

        if ($employee->user) {
            app(OperationalNotifier::class)->send(
                $employee->user,
                'Probation completed',
                'Your probation period has been completed. Your CareVMA employment status is now Permanent.',
                'ProbationCompleted',
                '/employee/profile',
                ['employee_id' => $employee->id, 'regularisation_date' => $today->toDateString()]
            );
        }

        return response()->json([
            'message' => 'Probation ended and employee marked permanent.',
            'employee' => $employee,
            'probation' => $employee->probationStatusData($today),
        ]);
    }

    public function downloadEmployeeDocument(Employee $employee, int $index)
    {
        $documents = Employee::documentPaths($employee->upload_documents);
        abort_unless(array_key_exists($index, $documents), 404);

        $storage = $this->employeeDocumentStorageReference($documents[$index]);
        abort_unless($storage, 404);

        $disk = Storage::disk($storage['disk']);
        abort_unless($disk->exists($storage['path']), 404);

        return $disk->download($storage['path'], basename($storage['path']));
    }

    public function destroyEmployee(Employee $employee)
    {
        if ($employee->clientSchedules()->exists() || $employee->attendances()->exists() || $employee->salaries()->exists()) {
            return response()->json(['message' => 'Employee cannot be deleted while schedules, attendance, or billing history is linked. Disable or reassign the employee instead.'], 422);
        }

        return DB::transaction(function () use ($employee) {
            $user = $employee->user;
            $employee->delete();
            $user?->delete();
            return response()->json(['message' => 'Employee deleted.']);
        });
    }


    public function hrUsers(Request $request)
    {
        $query = User::where('role', 'hr');
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        return response()->json($this->sortAndPaginate($request, $query, [
            'id' => 'id', 'name' => 'name', 'email' => 'email', 'last_seen_at' => 'last_seen_at', 'created_at' => 'created_at',
        ], 'id'));
    }

    public function storeHrUser(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make(Str::random(64)),
            'role' => 'hr',
        ]);

        $token = Password::broker()->createToken($user);
        $setupUrl = route('password.reset', [
            'token' => $token,
            'email' => $user->email,
        ]);

        $mail = ['sent' => false, 'error' => null];
        try {
            Mail::to($user->email)->send(new HrInvitationMail($user, $setupUrl));
            $mail['sent'] = true;
        } catch (\Throwable $e) {
            $mail['error'] = $e->getMessage();
            report($e);
        }

        return response()->json([
            'message' => $mail['sent']
                ? 'HR account created and password setup email sent.'
                : 'HR account created, but the password setup email could not be sent. Check the Laravel mail log and SMTP settings.',
            'user' => $user,
            'invitation_sent' => $mail['sent'],
            'setup_url' => $setupUrl,
        ], 201);
    }

    public function updateHrUser(Request $request, User $user)
    {
        abort_unless($user->role === 'hr', 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
        ]);
        $payload = ['name' => $data['name'], 'email' => $data['email']];
        if (!empty($data['password'])) $payload['password'] = Hash::make($data['password']);
        $user->update($payload);
        return response()->json(['message' => 'HR account updated.', 'user' => $user->fresh()]);
    }

    public function destroyHrUser(User $user)
    {
        abort_unless($user->role === 'hr', 404);
        $user->delete();
        return response()->json(['message' => 'HR account deleted.']);
    }

    public function clients(Request $request)
    {
        $query = Client::with(['user:id,name,email,role,is_active', 'service', 'contractType']);
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('business_name', 'like', "%{$search}%"));
        }
        if ($request->filled('business_name')) $query->where('business_name', (string) $request->query('business_name'));
        if ($request->filled('country')) $query->where('country', (string) $request->query('country'));
        if ($request->filled('timezone')) $query->where('timezone', (string) $request->query('timezone'));
        return response()->json($this->sortAndPaginate($request, $query, [
            'id' => 'id', 'name' => 'name', 'email' => 'email', 'business_name' => 'business_name', 'country' => 'country',
            'timezone' => 'timezone', 'per_hour_charges' => 'per_hour_charges', 'created_at' => 'created_at',
        ], 'id'));
    }

    public function client(Client $client)
    {
        return response()->json($client->load(['user', 'service', 'contractType']));
    }

    public function storeClient(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // Client/Doctor chooses their own password from the signed invitation email.
            'business_name' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:60'],
            'country' => ['nullable', 'string', 'max:100'],
            'timezone' => ['required', 'timezone'],
            'per_hour_charges' => ['nullable', 'numeric', 'min:0'],
            'contract_type_id' => ['nullable', 'exists:contract_types,id'],
            'service_id' => ['nullable', 'exists:services,id'],
            'ring_center' => ['nullable', 'boolean'],
        ]);
        [$client, $user] = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make(Str::random(64)),
                'role' => 'client',
            ]);

            $client = Client::create(array_merge($data, ['user_id' => $user->id]));

            return [$client, $user];
        });

        $invite = $this->sendClientInvitation($user, $client);

        return response()->json([
            'message' => $invite['sent']
                ? 'Client / Doctor created and password setup invitation sent.'
                : 'Client / Doctor created. Email delivery failed; use the activation link below or resend the invitation.',
            'client' => $client->fresh(),
            'invitation_sent' => $invite['sent'],
            'activation_url' => $invite['url'],
        ], 201);
    }

    public function resendClientInvitation(Client $client)
    {
        $client->loadMissing('user');
        abort_unless($client->user, 422, 'This client does not have a login account.');

        $invite = $this->sendClientInvitation($client->user, $client);

        return response()->json([
            'message' => $invite['sent']
                ? 'Client / Doctor password setup invitation sent again.'
                : ($invite['error'] ?? 'Email delivery failed. Use the activation link below or check SMTP settings.'),
            'invitation_sent' => $invite['sent'],
            'activation_url' => $invite['url'],
        ]);
    }

    public function updateClient(Request $request, Client $client)
    {
        $removePicture = $request->boolean('remove_profile_picture') && !$request->hasFile('profile_picture');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($client->user_id)],
            'profile_picture' => ['nullable','file','image','mimes:jpg,jpeg,png,webp','max:5120'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:60'],
            'country' => ['nullable', 'string', 'max:100'],
            'timezone' => ['required', 'timezone'],
            'per_hour_charges' => ['nullable', 'numeric', 'min:0'],
            'contract_type_id' => ['nullable', 'exists:contract_types,id'],
            'service_id' => ['nullable', 'exists:services,id'],
            'ring_center' => ['nullable', 'boolean'],
        ]);
        $oldPicture = $client->profile_picture;
        if ($request->hasFile('profile_picture')) {
            $data['profile_picture'] = '/storage/'.$request->file('profile_picture')->store('clients/profile_pictures', 'public');
        } elseif ($removePicture) {
            $data['profile_picture'] = null;
        } else {
            unset($data['profile_picture']);
        }
        DB::transaction(function () use ($data, $client) {
            $previousTimezone = $client->timezone;
            $client->update($data);
            $client->user?->update(['name' => $data['name'], 'email' => $data['email']]);

            // Schedule wall-clock values are Doctor-local/canonical. If the client changes
            // timezone, keep the same wall-clock schedule and synchronize legacy metadata so
            // every downstream path resolves the new IANA timezone consistently.
            if ($previousTimezone !== $client->timezone) {
                EmployeeClientSchedule::where('client_id', $client->id)->update(['timezone' => $client->timezone]);
            }
        });
        if (array_key_exists('profile_picture', $data) && $oldPicture) {
            \App\Support\ProfilePhotoStorage::deleteOld($oldPicture, 'clients/profile_pictures');
        }
        return response()->json(['message' => 'Client updated.', 'client' => $client->fresh()]);
    }

    public function destroyClient(Client $client)
    {
        $hasAttendance = Attendance::where('client_id', $client->id)->exists();
        if ($client->employeeSchedules()->exists() || $client->salaries()->exists() || Invoice::where('client_id', $client->id)->exists() || $hasAttendance) {
            return response()->json(['message' => 'Client cannot be deleted while schedules, attendance, salaries, or invoices are linked.'], 422);
        }
        return DB::transaction(function () use ($client) {
            $user = $client->user;
            $client->delete();
            $user?->delete();
            return response()->json(['message' => 'Client deleted.']);
        });
    }


    private function offboardingData(Request $request): array
    {
        return $request->validate([
            'effective_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function recordOffboarding(string $type, int $id, string $action, ?string $date, ?string $reason, ?string $notes, Request $request): void
    {
        DB::table('offboarding_events')->insert([
            'subject_type' => $type, 'subject_id' => $id, 'action' => $action,
            'effective_date' => $date, 'reason' => $reason, 'notes' => $notes,
            'performed_by' => $request->user()?->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function deactivateEmployee(Request $request, Employee $employee)
    {
        $data = $this->offboardingData($request);
        if (Attendance::where('employee_id', $employee->id)->whereNull('clock_out')->exists()) {
            return response()->json(['message' => 'Employee is currently checked in. Check them out before deactivation.'], 422);
        }
        return DB::transaction(function () use ($request, $employee, $data) {
            $employee->update(['offboarded_at'=>$data['effective_date'],'offboarding_reason'=>$data['reason'],'offboarding_notes'=>$data['notes'] ?? null]);
            $employee->user?->update(['is_active'=>false]);
            EmployeeClientSchedule::where('employee_id',$employee->id)->where('enabled',true)->update(['enabled'=>false]);
            $this->recordOffboarding('employee',$employee->id,'deactivated',$data['effective_date'],$data['reason'],$data['notes'] ?? null,$request);
            return response()->json(['message'=>'Employee deactivated. Login and active schedules are disabled; history is preserved.','employee'=>$employee->fresh()->load('user')]);
        });
    }

    public function reactivateEmployee(Request $request, Employee $employee)
    {
        return DB::transaction(function () use ($request, $employee) {
            $employee->update(['offboarded_at'=>null,'offboarding_reason'=>null,'offboarding_notes'=>null]);
            $employee->user?->update(['is_active'=>true]);
            $this->recordOffboarding('employee',$employee->id,'reactivated',now()->toDateString(),'Reactivated',null,$request);
            return response()->json(['message'=>'Employee reactivated. Previous schedules remain disabled until Admin enables/assigns them.','employee'=>$employee->fresh()->load('user')]);
        });
    }

    public function deactivateClient(Request $request, Client $client)
    {
        $data = $this->offboardingData($request);
        if (Attendance::where('client_id', $client->id)->whereNull('clock_out')->exists()) {
            return response()->json(['message' => 'This Doctor has open attendance. Close it before deactivation.'], 422);
        }
        return DB::transaction(function () use ($request, $client, $data) {
            $client->update(['offboarded_at'=>$data['effective_date'],'offboarding_reason'=>$data['reason'],'offboarding_notes'=>$data['notes'] ?? null]);
            $client->user?->update(['is_active'=>false]);
            EmployeeClientSchedule::where('client_id',$client->id)->where('enabled',true)->update(['enabled'=>false]);
            $this->recordOffboarding('client',$client->id,'deactivated',$data['effective_date'],$data['reason'],$data['notes'] ?? null,$request);
            return response()->json(['message'=>'Doctor / Client deactivated. Login and active schedules are disabled; attendance and invoice history is preserved.','client'=>$client->fresh()->load('user')]);
        });
    }

    public function reactivateClient(Request $request, Client $client)
    {
        return DB::transaction(function () use ($request, $client) {
            $client->update(['offboarded_at'=>null,'offboarding_reason'=>null,'offboarding_notes'=>null]);
            $client->user?->update(['is_active'=>true]);
            $this->recordOffboarding('client',$client->id,'reactivated',now()->toDateString(),'Reactivated',null,$request);
            return response()->json(['message'=>'Doctor / Client reactivated. Previous schedules remain disabled until Admin enables/assigns them.','client'=>$client->fresh()->load('user')]);
        });
    }

    public function schedules(Request $request)
    {
        $query = EmployeeClientSchedule::with([
            'employee:id,first_name,last_name,employee_code,email,timezone,profile_picture',
            'client:id,name,business_name,timezone,profile_picture',
        ]);
        if ($request->filled('employee_id')) $query->where('employee_id', $request->integer('employee_id'));
        if ($request->filled('client_id')) $query->where('client_id', $request->integer('client_id'));
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('weekday', 'like', "%{$search}%")
                    ->orWhere('schedule_date', 'like', "%{$search}%")
                    ->orWhere('start_time', 'like', "%{$search}%")
                    ->orWhere('end_time', 'like', "%{$search}%")
                    ->orWhere('timezone', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn ($employee) => $employee
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('employee_code', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('client', fn ($client) => $client
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('business_name', 'like', "%{$search}%")
                        ->orWhere('timezone', 'like', "%{$search}%"));
            });
        }

        $decorate = function ($rows) {
            $rates = ClientEmployeeRate::query()
                ->whereIn('client_id', $rows->pluck('client_id')->unique()->filter()->values())
                ->whereIn('employee_id', $rows->pluck('employee_id')->unique()->filter()->values())
                ->get()
                ->keyBy(fn (ClientEmployeeRate $rate) => $rate->client_id.':'.$rate->employee_id);

            $rows->each(function (EmployeeClientSchedule $schedule) use ($rates) {
                $this->decorateScheduleForDisplay($schedule);
                $rate = $rates->get($schedule->client_id.':'.$schedule->employee_id);
                $schedule->setAttribute('per_hour_rate', $rate?->per_hour_rate);
            });
        };

        // The global schedules screen can contain thousands of rows; paginate it.
        // Client/employee-scoped callers keep the original array response for compatibility.
        if ($request->boolean('paginate')) {
            $base = clone $query;
            $paginator = $this->sortAndPaginate($request, $query, [
                'weekday' => [DB::raw("FIELD(weekday,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')"), 'start_time'],
                'start_time' => 'start_time', 'end_time' => 'end_time', 'no_of_hours' => 'no_of_hours',
                'timezone' => 'timezone', 'enabled' => 'enabled', 'schedule_date' => 'schedule_date', 'id' => 'id',
            ], 'weekday', 'asc', 50);
            $decorate($paginator->getCollection());

            // Tiles above the table: the same filtered set, before paging.
            $live = fn () => (clone $base)->where(fn ($q) => $q->where('enabled', true)->orWhereNull('enabled'));
            $summary = [
                'total' => (clone $base)->count(),
                'enabled' => $live()->count(),
                'weekly_hours' => round((float) $live()->where('repeat', true)->sum('no_of_hours'), 2),
                'clients' => (clone $base)->distinct()->count('client_id'),
                'employees' => (clone $base)->distinct()->count('employee_id'),
                'timezones' => (clone $base)->whereNotNull('timezone')->where('timezone', '!=', '')->distinct()->orderBy('timezone')->pluck('timezone')->values(),
            ];

            return response()->json(array_merge($paginator->toArray(), ['summary' => $summary]));
        }

        $rows = $query
            ->orderByRaw("FIELD(weekday,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')")
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();
        $decorate($rows);
        return response()->json($rows);
    }

    public function storeSchedule(Request $request)
    {
        $data = $this->validateSchedule($request);
        $data = $this->normalizeScheduleRecurrence($data);
        $data['no_of_hours'] = $this->hoursBetween($data['start_time'], $data['end_time']);
        $schedule = EmployeeClientSchedule::create($data);
        $this->invoiceAutoSchedule->forgetScheduleCaches();
        if ($request->filled('per_hour_rate')) {
            ClientEmployeeRate::updateOrCreate(['client_id' => $data['client_id'], 'employee_id' => $data['employee_id']], ['per_hour_rate' => $request->input('per_hour_rate')]);
        }
        $schedule->load(['employee:id,first_name,last_name,employee_code,email,timezone,profile_picture', 'client:id,name,business_name,timezone,profile_picture']);
        $this->decorateScheduleForDisplay($schedule);
        $schedule->setAttribute('per_hour_rate', ClientEmployeeRate::where('client_id', $schedule->client_id)->where('employee_id', $schedule->employee_id)->value('per_hour_rate'));
        return response()->json(['message' => 'Schedule created.', 'schedule' => $schedule], 201);
    }

    public function storeScheduleBatch(Request $request)
    {
        $payload = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'client_id' => ['required', 'exists:clients,id'],
            'per_hour_rate' => ['nullable', 'numeric', 'min:0'],
            'break_allowed_hours' => ['required', 'numeric', 'min:0', 'max:12.59'],
            'schedules' => ['required', 'array', 'min:1', 'max:7'],
            'schedules.*.id' => ['nullable', 'integer', 'exists:employee_client_schedules,id'],
            'schedules.*.weekday' => ['required', Rule::in(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'])],
            'schedules.*.start_time' => ['required', 'date_format:H:i'],
            'schedules.*.end_time' => ['required', 'date_format:H:i'],
            'schedules.*.repeat' => ['nullable', 'boolean'],
            'schedules.*.enabled' => ['nullable', 'boolean'],
            'schedules.*.schedule_date' => ['nullable', 'date'],
        ]);

        $employee = Employee::with('user')->findOrFail($payload['employee_id']);
        $client = Client::findOrFail($payload['client_id']);
        $saved = DB::transaction(function () use ($payload, $client) {
            $rows = collect();
            foreach ($payload['schedules'] as $item) {
                $schedulePayload = [
                    'employee_id' => $payload['employee_id'],
                    'client_id' => $payload['client_id'],
                    'weekday' => $item['weekday'],
                    'schedule_date' => $item['schedule_date'] ?? null,
                    'start_time' => $item['start_time'],
                    'end_time' => $item['end_time'],
                    'timezone' => $client->timezone,
                    'time_basis' => 'doctor',
                    'break_allowed_hours' => $payload['break_allowed_hours'],
                    'enabled' => $item['enabled'] ?? true,
                    'repeat' => $item['repeat'] ?? true,
                    'per_hour_rate' => $payload['per_hour_rate'] ?? null,
                ];
                $child = Request::create('/api/management/schedules', 'POST', $schedulePayload);
                $data = $this->normalizeScheduleRecurrence($this->validateSchedule($child));
                $data['no_of_hours'] = $this->hoursBetween($data['start_time'], $data['end_time']);

                $schedule = null;
                if (!empty($item['id'])) {
                    $schedule = EmployeeClientSchedule::query()
                        ->whereKey($item['id'])
                        ->where('employee_id', $payload['employee_id'])
                        ->where('client_id', $payload['client_id'])
                        ->firstOrFail();
                    $schedule->update($data);
                } else {
                    $schedule = EmployeeClientSchedule::create($data);
                }
                $rows->push($schedule);
            }

            if (array_key_exists('per_hour_rate', $payload) && $payload['per_hour_rate'] !== null) {
                ClientEmployeeRate::updateOrCreate(
                    ['client_id' => $payload['client_id'], 'employee_id' => $payload['employee_id']],
                    ['per_hour_rate' => $payload['per_hour_rate']]
                );
            }

            return $rows;
        });

        $this->invoiceAutoSchedule->forgetScheduleCaches();
        $operationalNotifier = app(\App\Services\OperationalNotifier::class);

        if ($employee->user) {
            $operationalNotifier->send(
                $employee->user,
                'Client assignment updated',
                'You were assigned to '.$client->name.' for '.count($payload['schedules']).' scheduled day(s).',
                'ScheduleAssignment',
                '/employee/schedule',
                ['employee_id' => $employee->id, 'client_id' => $client->id, 'schedule_count' => count($payload['schedules'])]
            );
        }

        // One audit notification per assignment action for Admin/HR observers.
        // Do not email this high-frequency audit stream and do not notify the actor.
        $actor = $request->user();
        $operationsRecipients = $operationalNotifier->operationsUsers()
            ->reject(fn ($recipient) => $actor && $recipient->id === $actor->id)
            ->values();
        foreach ($operationsRecipients as $operationsRecipient) {
            $operationalNotifier->send(
                $operationsRecipient,
                'VA assignment changed',
                ($actor?->name ?: 'A CareVMA user').' assigned '.$employee->first_name.' '.$employee->last_name.' to '.$client->name.' for '.count($payload['schedules']).' scheduled day(s).',
                'OperationalAudit',
                $operationsRecipient->role === 'hr' ? '/hr/schedules' : '/admin/schedules',
                ['employee_id' => $employee->id, 'client_id' => $client->id, 'schedule_count' => count($payload['schedules'])],
                false
            );
        }

        return response()->json([
            'message' => 'VA assignment saved. One assignment notification was sent to the VA.',
            'schedule_count' => $saved->count(),
        ], 201);
    }

    public function updateSchedule(Request $request, EmployeeClientSchedule $schedule)
    {
        $data = $this->validateSchedule($request);
        $data = $this->normalizeScheduleRecurrence($data, $schedule);
        $data['no_of_hours'] = $this->hoursBetween($data['start_time'], $data['end_time']);
        $schedule->update($data);
        $this->invoiceAutoSchedule->forgetScheduleCaches();
        if ($request->filled('per_hour_rate')) {
            ClientEmployeeRate::updateOrCreate(['client_id' => $data['client_id'], 'employee_id' => $data['employee_id']], ['per_hour_rate' => $request->input('per_hour_rate')]);
        }
        $schedule = $schedule->fresh(['employee:id,first_name,last_name,employee_code,email,timezone,profile_picture', 'client:id,name,business_name,timezone,profile_picture']);
        $this->decorateScheduleForDisplay($schedule);
        $schedule->setAttribute('per_hour_rate', ClientEmployeeRate::where('client_id', $schedule->client_id)->where('employee_id', $schedule->employee_id)->value('per_hour_rate'));
        return response()->json(['message' => 'Schedule updated.', 'schedule' => $schedule]);
    }

    public function destroySchedule(EmployeeClientSchedule $schedule)
    {
        $schedule->delete();
        $this->invoiceAutoSchedule->forgetScheduleCaches();
        return response()->json(['message' => 'Schedule deleted.']);
    }

    public function attendance(Request $request)
    {
        $query = Attendance::with([
            'employee:id,first_name,last_name,employee_code,timezone,profile_picture',
            'breaks',
            'schedule:id,client_id,employee_id,start_time,end_time,timezone,break_allowed_hours',
        ]);
        $query->with('client:id,name,business_name,timezone,profile_picture');
        if ($request->filled('employee_id')) $query->where('employee_id', $request->integer('employee_id'));
        $selectedClientId = $request->filled('client_id') ? $request->integer('client_id') : null;
        if ($selectedClientId) {
            $query->where('client_id', $selectedClientId);
        }
        $this->applyAttendanceLocalDateFilter(
            $query,
            $request->input('from'),
            $request->input('to'),
            $selectedClientId
        );
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('date', 'like', "%{$search}%")
                    ->orWhere('clock_in', 'like', "%{$search}%")
                    ->orWhere('clock_out', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn ($e) => $e->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
            });
        }
        $attendance = $this->sortAndPaginate($request, $query, [
            'date' => 'date', 'clock_in' => 'clock_in', 'clock_out' => 'clock_out', 'total_minutes' => 'total_minutes',
            'late_minutes' => 'late_minutes', 'overtime' => 'overtime', 'break_taken' => 'break_taken', 'id' => 'id',
        ], 'date');
        $attendance->through(function (Attendance $row) {
            $clientZone = $this->timezones->forAttendance($row, true);
            $employeeZone = $this->timezones->valid($row->employee?->timezone, 'UTC');
            $row->setAttribute('client_timezone', $clientZone);
            $row->setAttribute('employee_timezone', $employeeZone);
            $row->setAttribute('clock_in_client', $this->timezones->isoInZone($row->clock_in, $clientZone));
            $row->setAttribute('clock_out_client', $this->timezones->isoInZone($row->clock_out, $clientZone));
            $row->setAttribute('clock_in_employee', $this->timezones->isoInZone($row->clock_in, $employeeZone));
            $row->setAttribute('clock_out_employee', $this->timezones->isoInZone($row->clock_out, $employeeZone));

            // Live attendance must show the real break records, not a stale aggregate field.
            // Completed rows keep the persisted aggregate for backward compatibility.
            if (!$row->clock_out && $row->relationLoaded('breaks')) {
                $nowUtc = $this->clock->now('UTC');
                $breakSeconds = (int) $row->breaks->sum(function ($break) use ($nowUtc) {
                    if (!$break->start_time) return 0;
                    $start = Carbon::parse($break->start_time)->utc();
                    $end = $break->end_time ? Carbon::parse($break->end_time)->utc() : $nowUtc;
                    return max(0, $end->timestamp - $start->timestamp);
                });
                $row->setAttribute('break_taken', $breakSeconds);
            }

            // Keep every attendance surface aligned with the billing rule:
            // effective break = max(assigned allowance, actual break taken).
            $actualBreakMinutes = (int) floor(max(0, (int) ($row->break_taken ?? 0)) / 60);
            $allowedBreakMinutes = 0;
            if (!empty($row->break_limit)) {
                $parts = array_map('intval', explode(':', (string) $row->break_limit));
                $allowedBreakMinutes = max(0, (($parts[0] ?? 0) * 60) + ($parts[1] ?? 0));
            } elseif ($row->schedule) {
                $raw = number_format(max(0, (float) ($row->schedule->break_allowed_hours ?? 0)), 2, '.', '');
                [$hours, $minutes] = array_pad(explode('.', $raw, 2), 2, '00');
                $allowedBreakMinutes = ((int) $hours * 60) + min(59, max(0, (int) $minutes));
            }
            $effectiveBreakMinutes = max($allowedBreakMinutes, $actualBreakMinutes);
            $regularMinutes = max(0, (int) ($row->total_minutes ?? 0));
            $approvedOvertime = ($row->overtime_status ?? null) === 'approved'
                ? min(max(0, (int) ($row->approved_overtime_minutes ?? 0)), max(0, (int) ($row->overtime ?? 0)))
                : 0;

            $row->setAttribute('allowed_break_minutes', $allowedBreakMinutes);
            $row->setAttribute('actual_break_minutes', $actualBreakMinutes);
            $row->setAttribute('effective_break_minutes', $effectiveBreakMinutes);
            $row->setAttribute('regular_minutes', $regularMinutes);
            $row->setAttribute('payable_minutes', $regularMinutes + $approvedOvertime);

            // Fallback shift window for older rows that pre-date shift snapshots.
            if ((!$row->shift_start_snapshot || !$row->shift_end_snapshot) && $row->schedule) {
                $date = $row->date?->toDateString() ?: ($row->clock_in?->copy()->setTimezone($clientZone)->toDateString());
                if ($date) {
                    $scheduleZone = $row->schedule->timezone ?: $clientZone;
                    $start = Carbon::parse($date.' '.$row->schedule->start_time, $scheduleZone);
                    $end = Carbon::parse($date.' '.$row->schedule->end_time, $scheduleZone);
                    if ($end->lte($start)) $end->addDay();
                    $row->setAttribute('scheduled_shift_start', $start->copy()->utc()->toIso8601ZuluString());
                    $row->setAttribute('scheduled_shift_end', $end->copy()->utc()->toIso8601ZuluString());
                }
            }

            return $row;
        });
        return response()->json($attendance);
    }

    private function applyAttendanceLocalDateFilter($query, ?string $from, ?string $to, ?int $clientId = null): void
    {
        if (!$from && !$to) return;

        // Modern CareVMA attendance persists `date` as the Doctor-local work date.
        // Filtering that indexed snapshot is both more correct and dramatically more
        // scalable than loading every Doctor timezone and building tens of thousands
        // of OR clauses. The clock_in envelope below is only for very old rows whose
        // local-date snapshot is missing.
        $query->where(function ($outer) use ($from, $to, $clientId) {
            $outer->where(function ($modern) use ($from, $to) {
                $modern->whereNotNull('date');
                if ($from) $modern->whereDate('date', '>=', $from);
                if ($to) $modern->whereDate('date', '<=', $to);
            });

            $outer->orWhere(function ($legacy) use ($from, $to, $clientId) {
                $legacy->whereNull('date');
                if ($clientId) $legacy->where('client_id', $clientId);
                if ($from) $legacy->where('clock_in', '>=', Carbon::parse($from, 'UTC')->subDays(2)->startOfDay());
                if ($to) $legacy->where('clock_in', '<=', Carbon::parse($to, 'UTC')->addDays(2)->endOfDay());
            });
        });
    }

    private function liveUsersFromWorkSessions(Request $request)
    {
        $serverNow = $this->clock->now('UTC');
        $status = (string) $request->query('status', 'checked_in');
        $perPage = min(max((int) $request->query('per_page', config('carevma.performance.live_workforce_per_page', 24)), 1), 48);
        // Presence must use the same clock source that writes users.last_seen_at.
        // RoleMiddleware updates last_seen_at with Laravel now(), while $serverNow is
        // the trusted business clock used for shift/attendance calculations. If the
        // trusted clock carries an offset, comparing last_seen_at against it can mark
        // every offline employee as online (or the reverse) and break pagination.
        $onlineSince = now()->subMinutes(5);

        // A manual Refresh must return fresh counters immediately instead of the
        // five-second metrics cache. Automatic polling keeps using the short cache
        // so large workforce pages remain lightweight.
        if ($request->has('_refresh')) {
            Cache::forget('carevma:live-workforce-metrics');
        }

        $metrics = Cache::remember('carevma:live-workforce-metrics', now()->addSeconds(5), function () use ($onlineSince) {
            $checkedIn = WorkSession::query()->whereNull('clock_out')->count();
            $onBreak = WorkSession::query()
                ->whereNull('clock_out')
                ->whereHas('breaks', fn ($q) => $q->whereNull('end_time'))
                ->count();
            $late = WorkSession::query()
                ->whereNull('clock_out')
                ->whereHas('attendances', fn ($q) => $q->whereNull('clock_out')->where('late_minutes', '>', 0))
                ->count();
            $online = Employee::query()
                ->whereHas('user', fn ($q) => $q->where('last_seen_at', '>', $onlineSince))
                ->count();
            $offline = $this->offlineEmployeeIdsQuery($onlineSince)->count();

            return [
                'total' => Employee::query()->count(),
                'online' => $online,
                'checked_in' => $checkedIn,
                'working' => max(0, $checkedIn - $onBreak),
                'on_break' => $onBreak,
                'late' => $late,
                'offline' => $offline,
            ];
        });

        if ($status === 'offline') {
            // Use one indexed paginator query for both the count and page slice.
            // The previous two-step ID query could report the correct total while
            // hydrating an empty page on MySQL page 2+. Joining only an open work
            // session row keeps the result one-row-per-employee and remains bounded.
            $requestedPage = max(1, (int) $request->query('page', 1));
            $paginator = Employee::query()
                ->leftJoin('users as live_workforce_users', 'live_workforce_users.id', '=', 'employees.user_id')
                ->leftJoin('work_sessions as live_open_sessions', function ($join) {
                    $join->on('live_open_sessions.employee_id', '=', 'employees.id')
                        ->whereNull('live_open_sessions.clock_out');
                })
                ->whereNull('live_open_sessions.id')
                ->where(function ($query) use ($onlineSince) {
                    $query->whereNull('employees.user_id')
                        ->orWhereNull('live_workforce_users.id')
                        ->orWhereNull('live_workforce_users.last_seen_at')
                        ->orWhere('live_workforce_users.last_seen_at', '<=', $onlineSince);
                })
                ->select('employees.*')
                ->with('user:id,name,email,last_seen_at')
                ->orderBy('employees.first_name')
                ->orderBy('employees.last_name')
                ->orderBy('employees.id')
                ->paginate($perPage, ['*'], 'page', $requestedPage);

            $paginator->setCollection(
                $paginator->getCollection()
                    ->map(fn (Employee $employee) => $this->mapOfflineEmployee($employee, $serverNow))
                    ->values()
            );

            return response()->json(array_merge($metrics, [
                'server_now' => $serverNow->toIso8601ZuluString(),
                'sessions' => $paginator->items(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total_filtered' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ]));
        } else {
            $query = WorkSession::query()
                ->with([
                    'employee.user:id,name,email,last_seen_at',
                    'attendances.client:id,name,business_name,timezone,profile_picture,profile_picture',
                    'attendances.schedule:id,weekday,start_time,end_time,timezone,break_allowed_hours',
                    'attendances.breaks:id,attendance_id,start_time,end_time',
                    'breaks:id,work_session_id,start_time,end_time',
                ])
                ->whereNull('clock_out');

            if ($status === 'break') {
                $query->whereHas('breaks', fn ($q) => $q->whereNull('end_time'));
            } elseif ($status === 'late') {
                $query->whereHas('attendances', fn ($q) => $q->whereNull('clock_out')->where('late_minutes', '>', 0));
            }

            $paginator = $query->latest('clock_in')->latest('id')->paginate($perPage);
            $paginator->setCollection($paginator->getCollection()->map(fn (WorkSession $session) => $this->mapLiveWorkSession($session, $serverNow)));
        }

        return response()->json(array_merge($metrics, [
            'server_now' => $serverNow->toIso8601ZuluString(),
            'sessions' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total_filtered' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ]));
    }

    private function offlineEmployeeIdsQuery(Carbon $onlineSince)
    {
        return DB::table('employees')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('work_sessions as open_work_sessions')
                    ->whereColumn('open_work_sessions.employee_id', 'employees.id')
                    ->whereNull('open_work_sessions.clock_out');
            })
            ->where(function ($query) use ($onlineSince) {
                $query->whereNull('employees.user_id')
                    ->orWhereNotExists(function ($missingUser) {
                        $missingUser->selectRaw('1')
                            ->from('users as missing_live_users')
                            ->whereColumn('missing_live_users.id', 'employees.user_id');
                    })
                    ->orWhereExists(function ($offlineUser) use ($onlineSince) {
                        $offlineUser->selectRaw('1')
                            ->from('users as offline_live_users')
                            ->whereColumn('offline_live_users.id', 'employees.user_id')
                            ->where(function ($user) use ($onlineSince) {
                                $user->whereNull('offline_live_users.last_seen_at')
                                    ->orWhere('offline_live_users.last_seen_at', '<=', $onlineSince);
                            });
                    });
            });
    }

    private function mapLiveWorkSession(WorkSession $session, Carbon $serverNow): array
    {
        $employee = $session->employee;
        $active = $session->attendances->whereNull('clock_out')->values();
        $all = $session->attendances->values();
        $primary = $active->sortByDesc(fn ($row) => $row->shift_end_snapshot?->timestamp ?? 0)->first() ?: $all->last();
        $doctorNames = $active->map(fn ($row) => $row->client?->name ?: $row->client?->business_name)->filter()->unique()->values();
        $doctorSegments = $active->map(function (Attendance $attendance) use ($serverNow) {
            $zone = $this->timezones->forAttendance($attendance, false);
            $start = $attendance->shift_start_snapshot?->copy()->setTimezone($zone ?: 'UTC');
            $end = $attendance->shift_end_snapshot?->copy()->setTimezone($zone ?: 'UTC');
            return [
                'attendance_id' => $attendance->id,
                'client_id' => $attendance->client_id,
                'name' => $attendance->client?->name ?: $attendance->client?->business_name ?: 'Doctor',
                'profile_picture' => $attendance->client?->profile_picture,
                'timezone' => $zone,
                'shift_label' => ($start && $end) ? $start->format('D, M d, g:i A').' – '.$end->format('D, M d, g:i A') : null,
                'shift_ends_at' => $attendance->shift_end_snapshot?->copy()->utc()->toIso8601ZuluString(),
                'shift_end_label' => $end?->format('D, M d, g:i A'),
                'late_minutes' => (int) ($attendance->late_minutes ?? 0),
                'clock_in_utc' => $attendance->clock_in?->copy()->utc()->toIso8601ZuluString(),
                'elapsed_seconds' => $attendance->clock_in ? max(0, $serverNow->timestamp - $attendance->clock_in->copy()->utc()->timestamp) : 0,
                'status' => 'active',
            ];
        })->values();
        $openBreak = $session->breaks->whereNull('end_time')->sortByDesc('id')->first();
        $completed = (int) $session->breaks->whereNotNull('end_time')->sum(fn ($break) => max(0, $break->end_time->timestamp - $break->start_time->timestamp));
        $current = $openBreak ? max(0, $serverNow->timestamp - $openBreak->start_time->timestamp) : 0;
        $elapsed = $session->clock_in ? max(0, $serverNow->timestamp - $session->clock_in->timestamp) : 0;
        $lastSeen = $employee?->user?->last_seen_at;
        $online = $lastSeen && Carbon::parse($lastSeen)->gt($serverNow->copy()->subMinutes(5));
        $late = (int) ($active->max(fn ($row) => (int) ($row->late_minutes ?? 0)) ?? 0);
        $zone = $primary ? $this->timezones->forAttendance($primary, false) : null;
        $scheduleLabel = null;
        if ($primary?->shift_start_snapshot && $primary?->shift_end_snapshot) {
            $start = $primary->shift_start_snapshot->copy()->setTimezone($zone ?: 'UTC');
            $end = $primary->shift_end_snapshot->copy()->setTimezone($zone ?: 'UTC');
            $scheduleLabel = $start->format('D, M d, g:i A').' – '.$end->format('D, M d, g:i A');
        }

        return [
            'id' => 'work-session-'.$session->id,
            'work_session_id' => $session->id,
            'employee' => $employee,
            'client' => $primary?->client,
            'clients' => $active->pluck('client')->filter()->values(),
            'doctor_names' => $doctorNames,
            'doctor_segments' => $doctorSegments,
            'schedule' => $primary?->schedule,
            'clock_in' => $session->clock_in?->toIso8601ZuluString(),
            'clock_in_utc' => $session->clock_in?->toIso8601ZuluString(),
            'clock_in_doctor_label' => ($zone && $session->clock_in) ? $session->clock_in->copy()->setTimezone($zone)->format('M d, Y, g:i A') : null,
            'elapsed_seconds' => $elapsed,
            'doctor_timezone' => $zone,
            'schedule_doctor_label' => $scheduleLabel,
            'late_minutes' => $late,
            'break_limit' => $primary?->break_limit,
            'completed_break_seconds' => $completed,
            'current_break_seconds' => $current,
            'total_break_seconds' => $completed + $current,
            'is_on_break' => (bool) $openBreak,
            'is_online' => (bool) $online,
            'last_seen_at' => $lastSeen?->toIso8601String(),
            'attendance_status' => 'Checked in',
            'status_key' => $openBreak ? 'break' : ($late > 0 ? 'late' : 'checked_in'),
            'status_label' => $openBreak ? 'On Break' : ($late > 0 ? 'Late' : 'Checked In'),
        ];
    }

    private function mapOfflineEmployee(Employee $employee, Carbon $serverNow): array
    {
        $lastSeen = $employee->user?->last_seen_at;
        return [
            'id' => 'employee-'.$employee->id,
            'employee' => $employee,
            'client' => null,
            'clients' => [],
            'doctor_names' => [],
            'doctor_segments' => [],
            'schedule' => null,
            'clock_in' => null,
            'elapsed_seconds' => 0,
            'doctor_timezone' => null,
            'schedule_doctor_label' => null,
            'late_minutes' => 0,
            'completed_break_seconds' => 0,
            'current_break_seconds' => 0,
            'total_break_seconds' => 0,
            'is_on_break' => false,
            'is_online' => false,
            'last_seen_at' => $lastSeen?->toIso8601String(),
            'attendance_status' => 'Not checked in',
            'status_key' => 'offline',
            'status_label' => 'Offline',
        ];
    }

    public function changeRequests(Request $request)
    {
        $query = AttendanceChangeRequest::with(['employee:id,first_name,last_name,employee_code', 'attendance']);
        if ($request->filled('status')) $query->where('status', $request->input('status'));
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('reason', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('requested_clock_in', 'like', "%{$search}%")
                    ->orWhere('requested_clock_out', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn ($employee) => $employee
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('employee_code', 'like', "%{$search}%"))
                    ->orWhereHas('attendance', fn ($attendance) => $attendance->where('date', 'like', "%{$search}%"));
            });
        }
        return response()->json($this->sortAndPaginate($request, $query, [
            'id' => 'id', 'date' => 'date', 'status' => 'status', 'requested_clock_in' => 'requested_clock_in',
            'requested_clock_out' => 'requested_clock_out', 'created_at' => 'created_at', 'reviewed_at' => 'reviewed_at',
        ], 'id'));
    }

    public function reviewChangeRequest(Request $request, AttendanceChangeRequest $changeRequest)
    {
        $data=$request->validate(['decision'=>['required',Rule::in(['approved','rejected'])],'note'=>['nullable','string','max:1000'],'approved_clock_in'=>['nullable','date_format:H:i'],'approved_clock_out'=>['nullable','date_format:H:i']]);
        if($changeRequest->status!=='pending')return response()->json(['message'=>'This request has already been reviewed.'],422);

        $affectedAttendanceId = null;
        $previousLocalDate = null;
        DB::transaction(function() use($changeRequest,$data,$request,&$affectedAttendanceId,&$previousLocalDate){
            $approvedIn=$data['approved_clock_in']??substr((string)$changeRequest->requested_clock_in,0,5); $approvedOut=$data['approved_clock_out']??substr((string)$changeRequest->requested_clock_out,0,5);
            if($data['decision']==='approved'){
                $attendance=$changeRequest->attendance()->lockForUpdate()->firstOrFail();
                $previousLocalDate=optional($attendance->date)->toDateString() ?: Carbon::parse($changeRequest->date)->toDateString();
                $date=Carbon::parse($changeRequest->date)->toDateString();$zone=$this->timezones->forAttendance($attendance,false);$in=Carbon::parse($date.' '.$approvedIn,$zone);$out=Carbon::parse($date.' '.$approvedOut,$zone);if($out->lte($in))$out->addDay();$attendance->clock_in=$in->utc();$attendance->clock_out=$out->utc();$attendance->save();$this->recalculateAttendance($attendance);
                $affectedAttendanceId=$attendance->id;
            }
            $update=['status'=>$data['decision'],'reviewed_by'=>$request->user()->id,'reviewed_at'=>$this->clock->now('UTC'),'review_note'=>$data['note']??null]; $update['approved_clock_in']=$data['decision']==='approved'?$approvedIn:null; $update['approved_clock_out']=$data['decision']==='approved'?$approvedOut:null; $changeRequest->update($update);
        });

        if ($affectedAttendanceId) {
            $this->invoiceDraftSync->syncForAttendance(Attendance::findOrFail($affectedAttendanceId), $previousLocalDate);
        }

        return response()->json(['message'=>'Change request '.$data['decision'].'.']);
    }

    public function scheduleChangeRequests(Request $request)
    {
        $query = ScheduleChangeRequest::with(['employee:id,first_name,last_name,employee_code','schedule.client:id,name,business_name,timezone,profile_picture']);
        if ($request->filled('status')) $query->where('status', $request->input('status'));
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('reason', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('requested_date', 'like', "%{$search}%")
                    ->orWhere('requested_start_time', 'like', "%{$search}%")
                    ->orWhere('requested_end_time', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn ($employee) => $employee
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('employee_code', 'like', "%{$search}%"))
                    ->orWhereHas('schedule.client', fn ($client) => $client
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('business_name', 'like', "%{$search}%")
                        ->orWhere('timezone', 'like', "%{$search}%"));
            });
        }
        return response()->json($query->latest('id')->paginate(min(max((int)$request->query('per_page',10),1),100)));
    }

    public function reviewScheduleChangeRequest(Request $request, ScheduleChangeRequest $scheduleChangeRequest)
    {
        $data=$request->validate(['decision'=>['required',Rule::in(['approved','rejected'])],'note'=>['nullable','string','max:1000'],'approved_date'=>['nullable','date'],'approved_start_time'=>['nullable','date_format:H:i'],'approved_end_time'=>['nullable','date_format:H:i']]); if($scheduleChangeRequest->status!=='pending')return response()->json(['message'=>'This schedule change request has already been reviewed.'],422);
        DB::transaction(function() use($scheduleChangeRequest,$data,$request){$date=$data['approved_date']??$scheduleChangeRequest->requested_date?->toDateString();$start=$data['approved_start_time']??substr((string)$scheduleChangeRequest->requested_start_time,0,5);$end=$data['approved_end_time']??substr((string)$scheduleChangeRequest->requested_end_time,0,5);if($data['decision']==='approved'){$schedule=$scheduleChangeRequest->schedule()->lockForUpdate()->firstOrFail();$payload=['start_time'=>$start,'end_time'=>$end];if(!$schedule->repeat&&$date)$payload['schedule_date']=$date;$payload['no_of_hours']=$this->hoursBetween($start,$end);$schedule->update($payload);} $scheduleChangeRequest->update(['status'=>$data['decision'],'approved_date'=>$data['decision']==='approved'?$date:null,'approved_start_time'=>$data['decision']==='approved'?$start:null,'approved_end_time'=>$data['decision']==='approved'?$end:null,'reviewed_by'=>$request->user()->id,'reviewed_at'=>$this->clock->now('UTC'),'review_note'=>$data['note']??null]);}); return response()->json(['message'=>'Schedule change request '.$data['decision'].'.']);
    }

    public function overtime(Request $request)
    {
        $query = Attendance::with(['employee:id,first_name,last_name,employee_code','client:id,name,business_name,timezone,profile_picture'])->where('overtime', '>', 0);
        $query->whereIn('overtime_status', ['review_required', 'pending', 'approved', 'rejected']);
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('date', 'like', "%{$search}%");
                $q->orWhere('overtime_status', 'like', "%{$search}%");
                $q->orWhereHas('employee', fn ($employee) => $employee
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%"))
                    ->orWhereHas('client', fn ($client) => $client
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('business_name', 'like', "%{$search}%"));
            });
        }
        return response()->json($this->sortAndPaginate($request, $query, [
            'id' => 'id', 'date' => 'date', 'overtime' => 'overtime', 'overtime_status' => 'overtime_status',
            'approved_overtime_minutes' => 'approved_overtime_minutes', 'overtime_amount' => 'overtime_amount',
        ], 'date'));
    }

    public function reviewOvertime(Request $request, Attendance $attendance)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'approved_minutes' => ['nullable', 'integer', 'min:0', 'max:'.$attendance->overtime],
            'overtime_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $attendance->update([
            'overtime_status' => $data['decision'],
            'approved_overtime_minutes' => $data['decision'] === 'approved' ? ($data['approved_minutes'] ?? $attendance->overtime) : 0,
            'overtime_amount' => $data['decision'] === 'approved' ? ($data['overtime_amount'] ?? null) : null,
            'overtime_approved_by' => $request->user()->id,
            'overtime_approved_at' => $this->clock->now('UTC'),
        ]);
        $this->invoiceDraftSync->syncForAttendance($attendance->fresh());
        return response()->json(['message' => 'Overtime review saved.']);
    }

    public function companyFinance(Request $request)
    {
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['day', 'month', 'year', 'range'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'start_date' => ['exclude_unless:view,range', 'required', 'date_format:Y-m-d'],
            'end_date' => ['exclude_unless:view,range', 'required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $view = $filters['view'] ?? 'month';
        $timezone = $this->timezones->businessTimezone();
        $anchor = isset($filters['date']) ? Carbon::parse($filters['date'], $timezone) : $this->clock->now($timezone);

        [$from, $to] = match ($view) {
            'day' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
            'year' => [$anchor->copy()->startOfYear(), $anchor->copy()->endOfYear()],
            'range' => [Carbon::parse($filters['start_date'], $timezone)->startOfDay(), Carbon::parse($filters['end_date'], $timezone)->endOfDay()],
            default => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
        };

        // The day view's trend is the week ending on the anchor, so load that whole
        // window. financeSummaryFromDaily still filters the summary to $from..$to, so
        // the day's own figures are unaffected. Previously the series covered only the
        // anchor day and the six earlier points in the trend were always zero.
        $seriesFrom = $view === 'day' ? $anchor->copy()->subDays(6)->startOfDay() : $from;
        $daily = $this->financeDailySeries($seriesFrom, $to);
        $summary = $this->financeSummaryFromDaily($daily, $from, $to);

        $trendUnit = $view === 'year' ? 'month' : 'day';
        if ($view === 'year') {
            $trend = collect(range(1, 12))->map(function ($month) use ($from, $daily) {
                $point = $from->copy()->month($month);
                $snapshot = $this->financeSummaryFromDaily($daily, $point->copy()->startOfMonth(), $point->copy()->endOfMonth());
                return ['label' => $point->format('M'), 'income' => $snapshot['incoming'], 'outgoing' => $snapshot['outgoing'], 'net' => $snapshot['net']];
            });
        } elseif ($view === 'month') {
            $trend = collect(range(1, $anchor->daysInMonth))->map(function ($day) use ($anchor, $daily) {
                $point = $anchor->copy()->day($day);
                $snapshot = $daily[$point->toDateString()] ?? $this->emptyFinanceSnapshot();
                return ['label' => (string) $day, 'income' => $snapshot['incoming'], 'outgoing' => $snapshot['outgoing'], 'net' => $snapshot['net']];
            });
        } elseif ($view === 'range') {
            $trendUnit = match (true) {
                $to->lt($from->copy()->addDays(31)) => 'day',
                $to->lt($from->copy()->addYearNoOverflow()) => 'month',
                default => 'year',
            };
            $trend = $this->financeRangeTrend($daily, $from, $to, $trendUnit);
        } else {
            // range(6, 0) already ends on the anchor day; the extra push() that used to
            // follow drew today twice and made this a seven-day chart with eight points.
            $trend = collect(range(6, 0))->map(function ($offset) use ($anchor, $daily) {
                $point = $anchor->copy()->subDays($offset);
                $snapshot = $daily[$point->toDateString()] ?? $this->emptyFinanceSnapshot();
                return ['label' => $point->format('D'), 'income' => $snapshot['incoming'], 'outgoing' => $snapshot['outgoing'], 'net' => $snapshot['net']];
            });
        }

        $entriesPage = CompanyFinanceEntry::query()
            ->with('creator:id,name')
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->latest('entry_date')
            ->latest('id')
            ->paginate(min(max((int) $request->query('per_page', 25), 1), 100));

        return response()->json([
            'view' => $view,
            'trend_unit' => $trendUnit,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => $summary,
            'trend' => $trend,
            'entries' => $entriesPage->items(),
            'entries_pagination' => $entriesPage->toArray(),
        ]);
    }

    private function financeRangeTrend(array $daily, Carbon $from, Carbon $to, string $unit): array
    {
        $format = match ($unit) {
            'year' => 'Y',
            'month' => 'Y-m',
            default => 'Y-m-d',
        };

        $labelLength = strlen($from->format($format));
        $buckets = [];
        foreach ($daily as $date => $snapshot) {
            $key = substr($date, 0, $labelLength);
            $buckets[$key]['income'] = ($buckets[$key]['income'] ?? 0) + $snapshot['incoming'];
            $buckets[$key]['outgoing'] = ($buckets[$key]['outgoing'] ?? 0) + $snapshot['outgoing'];
        }

        $point = $from->copy()->startOf($unit);
        $trend = [];
        while ($point->lte($to)) {
            $label = $point->format($format);
            $income = round($buckets[$label]['income'] ?? 0, 2);
            $outgoing = round($buckets[$label]['outgoing'] ?? 0, 2);
            $trend[] = ['label' => $label, 'income' => $income, 'outgoing' => $outgoing, 'net' => round($income - $outgoing, 2)];
            $point->add($unit, 1);
        }

        return $trend;
    }

    public function storeCompanyFinanceEntry(Request $request)
    {
        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'type' => ['required', Rule::in(['income', 'expense'])],
            'category' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['created_by'] = $request->user()->id;

        $entry = CompanyFinanceEntry::create($data)->load('creator:id,name');
        return response()->json(['message' => 'Finance entry saved.', 'entry' => $entry], 201);
    }

    public function destroyCompanyFinanceEntry(CompanyFinanceEntry $entry)
    {
        $entry->delete();
        return response()->json(['message' => 'Finance entry deleted.']);
    }

    private function emptyFinanceSnapshot(): array
    {
        return [
            'billed' => 0.0,
            'invoice_received' => 0.0,
            'manual_income' => 0.0,
            'incoming' => 0.0,
            'outgoing' => 0.0,
            'net' => 0.0,
            'outstanding' => 0.0,
        ];
    }

    private function financeDailySeries(Carbon $from, Carbon $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $daily = [];

        $invoiceRows = Invoice::query()
            ->selectRaw("period_end, COALESCE(SUM(total_amount),0) as billed, COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END),0) as received")
            ->whereBetween('period_end', [$fromDate, $toDate])
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', '!=', 'void');
            })
            ->groupBy('period_end')
            ->get();

        foreach ($invoiceRows as $row) {
            $key = Carbon::parse($row->period_end)->toDateString();
            $daily[$key] = $this->emptyFinanceSnapshot();
            $daily[$key]['billed'] = (float) $row->billed;
            $daily[$key]['invoice_received'] = (float) $row->received;
        }

        $entryRows = CompanyFinanceEntry::query()
            ->selectRaw("entry_date, COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END),0) as manual_income, COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END),0) as outgoing")
            ->whereBetween('entry_date', [$fromDate, $toDate])
            ->groupBy('entry_date')
            ->get();
        foreach ($entryRows as $row) {
            $key = Carbon::parse($row->entry_date)->toDateString();
            $daily[$key] ??= $this->emptyFinanceSnapshot();
            $daily[$key]['manual_income'] = (float) $row->manual_income;
            $daily[$key]['outgoing'] = (float) $row->outgoing;
        }

        foreach ($daily as $key => $snapshot) {
            $snapshot['incoming'] = $snapshot['invoice_received'] + $snapshot['manual_income'];
            $snapshot['net'] = $snapshot['incoming'] - $snapshot['outgoing'];
            $snapshot['outstanding'] = max(0, $snapshot['billed'] - $snapshot['invoice_received']);
            $daily[$key] = array_map(fn ($value) => is_float($value) || is_int($value) ? round((float) $value, 2) : $value, $snapshot);
        }

        return $daily;
    }

    private function financeSummaryFromDaily(array $daily, Carbon $from, Carbon $to): array
    {
        $summary = $this->emptyFinanceSnapshot();
        foreach ($daily as $date => $snapshot) {
            if ($date < $from->toDateString() || $date > $to->toDateString()) continue;
            foreach (['billed', 'invoice_received', 'manual_income', 'incoming', 'outgoing'] as $key) {
                $summary[$key] += (float) ($snapshot[$key] ?? 0);
            }
        }
        $summary['net'] = $summary['incoming'] - $summary['outgoing'];
        $summary['outstanding'] = max(0, $summary['billed'] - $summary['invoice_received']);
        return array_map(fn ($value) => round((float) $value, 2), $summary);
    }

    private function financeSnapshot(Carbon $from, Carbon $to): array
    {
        return $this->financeSummaryFromDaily($this->financeDailySeries($from, $to), $from, $to);
    }

    public function invoiceOverview(Request $request)
    {
        $month = trim((string) $request->query('month', now()->format('Y-m')));
        if (!preg_match('/^\\d{4}-\\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }
        $monthDate = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $monthEnd = $monthDate->copy()->endOfMonth();

        // Keep the month filter sargable so MySQL/MariaDB can use the billing-cycle
        // and period_end indexes even when the invoice table grows into millions of rows.
        $monthStart = $monthDate->toDateString();
        $monthEndDate = $monthEnd->toDateString();
        $invoiceQuery = Invoice::query()->where(function ($q) use ($monthStart, $monthEndDate) {
            $q->where('billing_cycle_month', $monthStart)
              ->orWhere(function ($fallback) use ($monthStart, $monthEndDate) {
                  $fallback->whereNull('billing_cycle_month')->whereBetween('period_end', [$monthStart, $monthEndDate]);
              });
        });

        $totalDoctors = Client::query()->count();
        $autoDoctors = (clone $invoiceQuery)->where('auto_generated', true)->distinct()->count('client_id');
        $manualDoctors = (clone $invoiceQuery)->where('auto_generated', false)->distinct()->count('client_id');
        $autoInvoices = (clone $invoiceQuery)->where('auto_generated', true)->count();
        $manualInvoices = (clone $invoiceQuery)->where('auto_generated', false)->count();
        $sentDoctors = Schema::hasColumn('invoices', 'sent_at')
            ? (clone $invoiceQuery)->whereNotNull('sent_at')->distinct()->count('client_id') : 0;
        $sentInvoices = Schema::hasColumn('invoices', 'sent_at')
            ? (clone $invoiceQuery)->whereNotNull('sent_at')->count() : 0;

        $failedDoctorIds = Schema::hasTable('invoice_generation_failures')
            ? DB::table('invoice_generation_failures')->where('billing_month', $month)->whereNull('resolved_at')->pluck('client_id')->unique()
            : collect();
        $failed = $failedDoctorIds->count();
        // Only the distinct Doctor count is needed here. Let SQL count it instead
        // of hydrating every matching client_id into PHP when invoice history grows.
        $coveredDoctorCount = (clone $invoiceQuery)->distinct()->count('client_id');
        $pending = max(0, $totalDoctors - $coveredDoctorCount - $failed);
        $remainingToSend = max(0, $coveredDoctorCount - $sentDoctors);

        return response()->json([
            'month' => $month,
            'total_doctors' => $totalDoctors,
            'auto_generated_invoices' => $autoInvoices,
            'auto_generated_doctors' => $autoDoctors,
            'manual_invoices' => $manualInvoices,
            'manual_doctors' => $manualDoctors,
            'pending_doctors' => $pending,
            'failed_doctors' => $failed,
            'sent_invoices' => $sentInvoices,
            'sent_doctors' => $sentDoctors,
            'remaining_to_send' => $remainingToSend,
        ]);
    }

    public function invoices(Request $request)
    {
        $query = Invoice::with([
            'client:id,name,business_name,timezone,profile_picture,service_id,contract_type_id',
            'client.service:id,name',
            'client.contractType:id,name',
        ]);

        if ($invoiceNumber = trim((string) $request->query('invoice_number', ''))) {
            $query->where('invoice_number', 'like', '%' . $invoiceNumber . '%');
        }

        if ($clientId = $request->query('client_id')) {
            $query->where('client_id', $clientId);
        }

        if ($status = trim((string) $request->query('status', ''))) {
            $query->where('status', $status);
        }

        if ($month = trim((string) $request->query('month', ''))) {
            if (preg_match('/^\d{4}-\d{2}$/', $month)) {
                $monthDate = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
                $monthStart = $monthDate->toDateString();
                $monthEnd = $monthDate->copy()->endOfMonth()->toDateString();
                $query->where(function ($monthQuery) use ($monthStart, $monthEnd) {
                    $monthQuery->where('billing_cycle_month', $monthStart)
                        ->orWhere(function ($fallback) use ($monthStart, $monthEnd) {
                            $fallback->whereNull('billing_cycle_month')
                                ->whereBetween('period_end', [$monthStart, $monthEnd]);
                        });
                });
            }
        }

        if ($year = (int) $request->query('year', 0)) {
            $query->whereYear('period_end', $year);
        }

        if ($from = $request->query('date_from')) {
            $query->whereDate('period_end', '>=', $from);
        }

        if ($to = $request->query('date_to')) {
            $query->whereDate('period_start', '<=', $to);
        }

        return response()->json($this->sortAndPaginate($request, $query, [
            'id' => 'id', 'invoice_number' => 'invoice_number', 'period_start' => 'period_start', 'period_end' => 'period_end',
            'total_hours' => 'total_hours', 'total_amount' => 'total_amount', 'status' => 'status', 'created_at' => 'created_at',
        ], 'id'));
    }

    public function generateInvoice(Request $request, InvoiceGenerator $generator)
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'invoice_month' => ['nullable', 'date_format:Y-m'],
            'period_start' => ['required_without:invoice_month', 'nullable', 'date'],
            'period_end' => ['required_without:invoice_month', 'nullable', 'date', 'after_or_equal:period_start'],
            'employee_ids' => ['nullable', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ]);
        $employeeIds = $data['employee_ids'] ?? null;

        // Bulk generation sends only the invoice month. Resolve the exact period here
        // from the same Invoice Settings/BillingCycle service used by auto generation.
        if (!empty($data['invoice_month'])) {
            [$data['period_start'], $data['period_end']] = BillingCycle::forInvoiceMonth($data['invoice_month']);
        }

        $client = Client::findOrFail($data['client_id']);
        try {
            $invoices = $generator->generateMany($client, $data['period_start'], $data['period_end'], false, $employeeIds);
            $invoice = $invoices->first();
        } catch (\Throwable $e) {
            Log::error('Manual invoice generation failed', [
                'client_id' => $client->id,
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Invoice generation failed because the live billing schema or attendance data is not compatible. The error has been logged for diagnosis.',
            ], 422);
        }

        if (!$invoice) {
            $who = $employeeIds ? 'the selected VAs' : 'this client';
            return response()->json(['message' => "No completed, unbilled attendance exists for {$who} in the selected client-local date range. VAs already invoiced for these dates are excluded."], 422);
        }

        return response()->json([
            'message' => 'Invoice generated as an editable draft.',
            // Keep both keys for UI/backward compatibility. One Doctor/cycle run
            // creates one invoice containing all eligible VA salary rows.
            'invoice' => $invoice,
            'invoices' => $invoices->values(),
        ], 201);
    }

    public function invoice(Invoice $invoice)
    {
        return response()->json($invoice->load([
            'client:id,name,business_name,timezone,profile_picture,service_id,contract_type_id',
            'client.service:id,name',
            'client.contractType:id,name',
            'salaries.employee:id,first_name,last_name,employee_code',
            'adjustments.employee:id,first_name,last_name,employee_code',
        ]));
    }

    public function sendInvoicePdf(Invoice $invoice, InvoicePdfService $documents)
    {
        $invoice->loadMissing('client');
        if (!$invoice->client?->email) {
            return response()->json(['message' => 'The selected Doctor / Client does not have an email address.'], 422);
        }

        $hadSnapshot = Schema::hasColumn('invoices', 'snapshot_at') && (bool) $invoice->snapshot_at;
        if (!$hadSnapshot) {
            $invoice = $this->invoiceSnapshots->freeze($invoice);
            $invoice->loadMissing('client');
        }

        try {
            // Render from the frozen snapshot so the emailed PDF is identical to
            // every later download of this financial record.
            $pdfContent = $documents->make($invoice)->output();
            Mail::to($invoice->client->email)->send(
                new InvoiceNotificationMail($invoice, $pdfContent)
            );
            $mail = ['sent' => true, 'error' => null];
            if (Schema::hasColumn('invoices', 'sent_at')) {
                $invoice->forceFill(['sent_at' => now(), 'send_failed_at' => null, 'send_error' => null])->save();
            }
        } catch (\Throwable $e) {
            report($e);
            if (!$hadSnapshot) {
                // A failed send must not accidentally freeze an otherwise editable draft.
                $this->invoiceSnapshots->clearDraftSnapshot($invoice);
            }
            if (Schema::hasColumn('invoices', 'send_failed_at')) {
                $invoice->forceFill(['send_failed_at' => now(), 'send_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            }
            return response()->json(['message' => 'Invoice email could not be prepared. Check the Laravel mail log and try again.'], 422);
        }

        if (!$mail['sent']) {
            return response()->json([
                'message' => 'Invoice email could not be sent. Check SMTP configuration and the Laravel mail log.',
            ], 422);
        }

        return response()->json([
            'message' => 'Invoice PDF sent to '.$invoice->client->email.'.',
            'recipient' => $invoice->client->email,
        ]);
    }

    public function destroyInvoice(Invoice $invoice)
    {
        DB::transaction(function () use ($invoice) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $reason = $this->invoiceHardDeleteBlockReason($locked);
            if ($reason) {
                throw ValidationException::withMessages(['invoice' => [$reason]]);
            }
            $this->hardDeleteInvoice($locked);
        });

        return response()->json(['message' => 'Draft invoice deleted successfully.']);
    }

    public function bulkDeleteInvoices(Request $request)
    {
        $data = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
            'invoice_ids.*' => ['integer', 'distinct', 'exists:invoices,id'],
        ]);

        $result = DB::transaction(function () use ($data) {
            $invoices = Invoice::query()
                ->whereIn('id', $data['invoice_ids'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $deleted = [];
            $protected = [];
            foreach ($invoices as $invoice) {
                if ($reason = $this->invoiceHardDeleteBlockReason($invoice)) {
                    $protected[] = [
                        'id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'reason' => $reason,
                    ];
                    continue;
                }

                $deleted[] = $invoice->id;
                $this->hardDeleteInvoice($invoice);
            }

            return ['deleted' => $deleted, 'protected' => $protected];
        });

        $deletedCount = count($result['deleted']);
        $protectedCount = count($result['protected']);

        return response()->json([
            'message' => $protectedCount
                ? "Bulk delete complete: {$deletedCount} draft invoice(s) deleted, {$protectedCount} protected invoice(s) kept."
                : "Bulk delete complete: {$deletedCount} draft invoice(s) deleted.",
            'deleted_count' => $deletedCount,
            'protected_count' => $protectedCount,
            'deleted_ids' => $result['deleted'],
            'protected' => $result['protected'],
        ]);
    }

    public function updateInvoice(Request $request, Invoice $invoice)
    {
        $previousStatus = $invoice->status ?? 'draft';
        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'status' => ['nullable', Rule::in(['draft', 'issued', 'paid', 'void'])],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer', 'exists:employee_salaries,id'],
            'lines.*.total_hours' => ['required', 'numeric', 'min:0'],
            'lines.*.total_late' => ['nullable', 'integer', 'min:0'],
            'lines.*.total_overtime' => ['nullable', 'numeric', 'min:0'],
            'lines.*.salary_amount' => ['required', 'numeric', 'min:0'],
            'adjustments' => ['nullable', 'array', 'max:50'],
            'adjustments.*.employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'adjustments.*.direction' => ['required_with:adjustments', Rule::in(['addition', 'deduction'])],
            'adjustments.*.category' => ['required_with:adjustments', Rule::in(['bonus', 'allowance', 'reimbursement', 'other'])],
            'adjustments.*.description' => ['nullable', 'string', 'max:500'],
            'adjustments.*.amount' => ['required_with:adjustments', 'numeric', 'gt:0', 'max:999999999.99'],
        ]);

        // Issued, sent, paid and void invoices are financial history. Amounts, period,
        // salary lines and adjustments are immutable after finalization. Only safe
        // forward status transitions remain available (Issued -> Paid/Void, Paid -> Void).
        $sentLocked = Schema::hasColumn('invoices', 'sent_at') && $invoice->sent_at;
        $locked = in_array($previousStatus, ['issued', 'paid', 'void'], true) || $sentLocked;

        if ($locked) {
            $targetStatus = $data['status'] ?? $previousStatus;
            $allowedTargets = match ($previousStatus) {
                'issued' => ['paid', 'void'],
                'paid' => ['void'],
                'void' => [],
                default => $sentLocked ? ['issued', 'paid', 'void'] : [],
            };

            if ($targetStatus === $previousStatus || !in_array($targetStatus, $allowedTargets, true)) {
                return response()->json([
                    'message' => 'This invoice has been finalized or sent. Its financial details are frozen; only an allowed status transition can be applied.',
                    'code' => 'INVOICE_LOCKED',
                ], 422);
            }

            DB::transaction(function () use ($invoice, $targetStatus, $request) {
                $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $lockedInvoice->update([
                    'status' => $targetStatus,
                    'edited_by' => $request->user()->id,
                    'edited_at' => $this->clock->now('UTC'),
                ]);
            });

            $fresh = $this->invoiceSnapshots->freeze($invoice->fresh());
            return response()->json([
                'message' => $targetStatus === 'paid' ? 'Invoice marked as paid.' : 'Invoice status updated.',
                'invoice' => $fresh,
            ]);
        }

        DB::transaction(function () use ($invoice, $data, $request) {
            $totalHours = 0;
            $totalAmount = 0;
            $effectiveLineStarts = [];

            foreach ($data['lines'] as $lineData) {
                $line = $invoice->salaries()->whereKey($lineData['id'])->firstOrFail();

                // First-month hard guard: even if Admin manually selects an older
                // invoice start date, a VA line can never begin before that VA's
                // first actual check-in with this Doctor/Client.
                $effectiveLineStart = Carbon::parse($data['period_start'])->toDateString();
                $firstCheckIn = Attendance::query()
                    ->where('client_id', $invoice->client_id)
                    ->where('employee_id', $line->employee_id)
                    ->whereNotNull('clock_in')
                    ->orderBy('clock_in')
                    ->value('clock_in');

                if ($firstCheckIn) {
                    $firstCheckInDate = $this->timezones->localDate($firstCheckIn, $invoice->client);
                    if ($firstCheckInDate > $effectiveLineStart) {
                        $effectiveLineStart = $firstCheckInDate;
                    }
                }

                if ($effectiveLineStart > Carbon::parse($data['period_end'])->toDateString()) {
                    throw ValidationException::withMessages([
                        'period_end' => 'The selected invoice period ends before this VA first checked in for the Doctor.',
                    ]);
                }

                $effectiveLineStarts[] = $effectiveLineStart;

                $line->update([
                    'total_hours' => round((float) $lineData['total_hours'], 2),
                    'total_late' => (int) ($lineData['total_late'] ?? 0),
                    'total_overtime' => (float) ($lineData['total_overtime'] ?? 0),
                    'salary_amount' => round((float) $lineData['salary_amount'], 2),
                    'period_start' => $effectiveLineStart,
                    'period_end' => $data['period_end'],
                ]);
                $totalHours += (float) $lineData['total_hours'];
                $totalAmount += (float) $lineData['salary_amount'];
            }

            // Keep attendance salary lines unchanged and store manual invoice
            // adjustments separately so the breakdown remains auditable.
            $invoice->adjustments()->delete();
            $adjustmentTotal = 0.0;
            foreach (($data['adjustments'] ?? []) as $adjustmentData) {
                $amount = round((float) $adjustmentData['amount'], 2);
                $direction = $adjustmentData['direction'];
                $invoice->adjustments()->create([
                    'employee_id' => $adjustmentData['employee_id'] ?? null,
                    'direction' => $direction,
                    'category' => $adjustmentData['category'],
                    'description' => $adjustmentData['description'] ?? null,
                    'amount' => $amount,
                ]);
                $adjustmentTotal += $direction === 'deduction' ? -$amount : $amount;
            }

            $grandTotal = max(0, round($totalAmount + $adjustmentTotal, 2));

            // Combined Doctor invoices use the earliest allowed included-VA start
            // for the header; each salary line keeps its own first-check-in clamp.
            $effectiveInvoiceStart = $effectiveLineStarts
                ? min($effectiveLineStarts)
                : Carbon::parse($data['period_start'])->toDateString();

            $invoice->update([
                'period_start' => $effectiveInvoiceStart,
                'period_end' => $data['period_end'],
                'status' => $data['status'] ?? $invoice->status ?? 'draft',
                'total_hours' => round($totalHours, 2),
                'total_amount' => $grandTotal,
                'edited_by' => $request->user()->id,
                'edited_at' => $this->clock->now('UTC'),
            ]);
        });

        $fresh = $invoice->fresh([
            'client:id,name,business_name,timezone,profile_picture',
            'salaries.employee:id,first_name,last_name,employee_code',
            'adjustments.employee:id,first_name,last_name,employee_code',
        ]);
        if (in_array($fresh->status ?? 'draft', ['issued', 'paid', 'void'], true) || $fresh->sent_at) {
            $fresh = $this->invoiceSnapshots->freeze($fresh);
        }
        $message = ($previousStatus !== 'issued' && $fresh->status === 'issued')
            ? 'Invoice marked as issued.'
            : (($previousStatus !== 'paid' && $fresh->status === 'paid') ? 'Invoice marked as paid.' : 'Invoice updated successfully.');

        return response()->json([
            'message' => $message,
            'invoice' => $fresh,
        ]);
    }

    private function forgetReferenceOptionsCache(): void
    {
        Cache::forget('carevma:reference-options:v1');
    }

    public function references(Request $request, string $type)
    {
        $this->assertReferenceAccess($request, $type);
        $model = $this->referenceModel($type);
        return response()->json($model::orderBy('name')->get());
    }

    public function storeReference(Request $request, string $type)
    {
        $this->assertReferenceAccess($request, $type, 'create');
        $model = $this->referenceModel($type);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:1000']]);
        $payload = ['name' => $data['name']];
        if ($type === 'departments') $payload['description'] = $data['description'] ?? null;
        $item = $model::create($payload);
        $this->forgetReferenceOptionsCache();
        return response()->json(['message' => 'Item created.', 'item' => $item], 201);
    }

    public function updateReference(Request $request, string $type, int $id)
    {
        $this->assertReferenceAccess($request, $type, 'edit');
        $model = $this->referenceModel($type); $item = $model::findOrFail($id);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:1000']]);
        $payload = ['name' => $data['name']];
        if ($type === 'departments') $payload['description'] = $data['description'] ?? null;
        $item->update($payload);
        $this->forgetReferenceOptionsCache();
        return response()->json(['message' => 'Item updated.', 'item' => $item]);
    }

    public function destroyReference(Request $request, string $type, int $id)
    {
        $this->assertReferenceAccess($request, $type, 'delete');
        $model = $this->referenceModel($type); $item = $model::findOrFail($id);
        try { $item->delete(); } catch (\Throwable $e) { return response()->json(['message' => 'This item is in use and cannot be deleted.'], 422); }
        $this->forgetReferenceOptionsCache();
        return response()->json(['message' => 'Item deleted.']);
    }

    public function pkrUsdRate(Request $request, FxRateService $fx)
    {
        try {
            return response()->json($fx->pkrToUsd($request->boolean('refresh')));
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'The live PKR/USD rate is temporarily unavailable. Please try again shortly; the last cached rate will continue to be used where available.',
                'code' => 'FX_RATE_UNAVAILABLE',
            ], 503);
        }
    }

    /**
     * Lightweight remote people lookup for high-volume installs.
     *
     * The old /management/options payload hydrated every Employee and Doctor on
     * every admin page. At 50k + 50k records that became a 100k-row JSON response
     * and the browser repeatedly paid the same cost. Searchable selects now call
     * this endpoint and receive at most a small page of matching people.
     */
    public function peopleOptions(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['employee', 'client', 'user'])],
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'value_field' => ['nullable', Rule::in(['id', 'user_id'])],
            'ids' => ['nullable', 'array', 'max:100'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $type = $data['type'];
        $limit = min(50, max(1, (int) ($data['limit'] ?? 30)));
        $valueField = $type === 'user' ? 'id' : ($data['value_field'] ?? 'id');
        $selectedIds = collect($data['ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $search = trim((string) ($data['q'] ?? ''));
        $prefix = addcslashes($search, '%_\\').'%';

        if ($type === 'employee') {
            $columns = ['id', 'user_id', 'employee_code', 'first_name', 'last_name', 'email', 'timezone'];
            $base = Employee::query()->select($columns);
            $selected = $selectedIds->isEmpty()
                ? collect()
                : (clone $base)->whereIn($valueField, $selectedIds)->get();

            $matches = (clone $base)
                ->when($search !== '', function ($query) use ($prefix) {
                    $query->where(function ($q) use ($prefix) {
                        $q->where('employee_code', 'like', $prefix)
                            ->orWhere('first_name', 'like', $prefix)
                            ->orWhere('last_name', 'like', $prefix)
                            ->orWhere('email', 'like', $prefix)
                            ->orWhere('timezone', 'like', $prefix);
                    });
                })
                ->orderBy($search !== '' ? 'first_name' : 'id', $search !== '' ? 'asc' : 'desc')
                ->limit($limit)
                ->get();

            $rows = $selected->concat($matches)->unique($valueField)->values()->map(function (Employee $employee) use ($valueField) {
                $name = trim($employee->first_name.' '.$employee->last_name);
                return [
                    'value' => (string) $employee->{$valueField},
                    'id' => $employee->id,
                    'user_id' => $employee->user_id,
                    'label' => trim(($employee->employee_code ? $employee->employee_code.' · ' : '').($name ?: $employee->email)),
                    'meta' => $employee->timezone ?: ($employee->email ?: 'Timezone not set'),
                    'searchText' => trim(($employee->email ?: '').' '.($employee->employee_code ?: '').' '.($employee->timezone ?: '')),
                    'timezone' => $employee->timezone,
                    'first_name' => $employee->first_name,
                    'last_name' => $employee->last_name,
                    'employee_code' => $employee->employee_code,
                    'email' => $employee->email,
                ];
            });
        } elseif ($type === 'client') {
            $columns = ['id', 'user_id', 'name', 'email', 'business_name', 'country', 'timezone', 'per_hour_charges'];
            $base = Client::query()->select($columns);
            $selected = $selectedIds->isEmpty()
                ? collect()
                : (clone $base)->whereIn($valueField, $selectedIds)->get();

            $matches = (clone $base)
                ->when($search !== '', function ($query) use ($prefix) {
                    $query->where(function ($q) use ($prefix) {
                        $q->where('name', 'like', $prefix)
                            ->orWhere('business_name', 'like', $prefix)
                            ->orWhere('email', 'like', $prefix)
                            ->orWhere('timezone', 'like', $prefix)
                            ->orWhere('country', 'like', $prefix);
                    });
                })
                ->orderBy($search !== '' ? 'name' : 'id', $search !== '' ? 'asc' : 'desc')
                ->limit($limit)
                ->get();

            $rows = $selected->concat($matches)->unique($valueField)->values()->map(function (Client $client) use ($valueField) {
                $label = $client->name ?: $client->business_name ?: $client->email ?: 'Doctor / Client';
                $meta = collect([$client->business_name, $client->timezone])->filter()->unique()->implode(' · ');
                return [
                    'value' => (string) $client->{$valueField},
                    'id' => $client->id,
                    'user_id' => $client->user_id,
                    'label' => $label,
                    'meta' => $meta ?: ($client->email ?: 'Timezone not set'),
                    'searchText' => trim(($client->business_name ?: '').' '.($client->email ?: '').' '.($client->timezone ?: '').' '.($client->country ?: '')),
                    'timezone' => $client->timezone,
                    'name' => $client->name,
                    'business_name' => $client->business_name,
                    'email' => $client->email,
                    'country' => $client->country,
                    'per_hour_charges' => $client->per_hour_charges,
                ];
            });
        } else {
            // Activity-log actor lookup. Search the compact users table instead of
            // materialising every distinct actor from activity_logs on each page load.
            $base = User::query()->select(['id', 'name', 'email', 'role']);
            $selected = $selectedIds->isEmpty()
                ? collect()
                : (clone $base)->whereIn('id', $selectedIds)->get();

            $matches = (clone $base)
                ->when($search !== '', function ($query) use ($prefix) {
                    $query->where(function ($q) use ($prefix) {
                        $q->where('name', 'like', $prefix)
                            ->orWhere('email', 'like', $prefix);
                    });
                })
                ->orderBy($search !== '' ? 'name' : 'id', $search !== '' ? 'asc' : 'desc')
                ->limit($limit)
                ->get();

            $rows = $selected->concat($matches)->unique('id')->values()->map(function (User $user) {
                return [
                    'value' => (string) $user->id,
                    'id' => $user->id,
                    'label' => $user->name ?: $user->email ?: 'User',
                    'meta' => collect([$user->role, $user->email])->filter()->implode(' · '),
                    'searchText' => trim(($user->email ?: '').' '.($user->role ?: '')),
                    'email' => $user->email,
                    'role' => $user->role,
                ];
            });
        }

        return response()->json(['data' => $rows, 'limit' => $limit]);
    }

    public function options()
    {
        $references = Cache::remember('carevma:reference-options:v1', now()->addMinutes(10), function () {
            return [
                'departments' => Department::orderBy('name')->get(['id', 'name', 'description']),
                'roles' => Role::orderBy('name')->get(['id', 'name']),
                'employment_types' => EmploymentType::orderBy('name')->get(['id', 'name']),
                'shift_types' => ShiftType::orderBy('name')->get(['id', 'name']),
                'designations' => Designation::orderBy('name')->get(['id', 'name']),
                'employee_statuses' => EmployeeStatus::orderBy('name')->get(['id', 'name']),
                'expertises' => Expertise::orderBy('name')->get(['id', 'name']),
                'reporting_managers' => ReportingManager::orderBy('name')->get(['id', 'name']),
                'services' => Service::orderBy('name')->get(['id', 'name']),
                'contracts' => ContractType::orderBy('name')->get(['id', 'name']),
            ];
        });

        $facets = Cache::remember('carevma:people-filter-facets:v2', now()->addMinutes(5), function () {
            return [
                'employee_timezones' => Employee::query()->whereNotNull('timezone')->where('timezone', '!=', '')->distinct()->orderBy('timezone')->pluck('timezone')->values(),
                'client_businesses' => Client::query()->whereNotNull('business_name')->where('business_name', '!=', '')->distinct()->orderBy('business_name')->limit(500)->pluck('business_name')->values(),
                'client_countries' => Client::query()->whereNotNull('country')->where('country', '!=', '')->distinct()->orderBy('country')->pluck('country')->values(),
                'client_timezones' => Client::query()->whereNotNull('timezone')->where('timezone', '!=', '')->distinct()->orderBy('timezone')->pluck('timezone')->values(),
            ];
        });

        // Deliberately do not include every employee/client here. Large installs use
        // /management/people-options for remote searchable selects. Keep empty arrays
        // for backward-compatible consumers that still inspect these keys.
        return response()->json(array_merge([
            'employees' => [],
            'clients' => [],
        ], $references, $facets, [
            'business_timezone' => $this->timezones->businessTimezone(),
        ]));
    }

    public function updateAccount(Request $request)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['admin', 'superadmin', 'hr'], true), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'profile_picture' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_profile_picture' => ['sometimes', 'boolean'],
        ]);

        $previousPicture = $user->profile_picture;
        $removePicture = $request->boolean('remove_profile_picture') && !$request->hasFile('profile_picture');
        unset($data['remove_profile_picture']);
        if ($request->hasFile('profile_picture')) {
            $data['profile_picture'] = '/storage/'.$request->file('profile_picture')->store('users/profile_pictures', 'public');
        } elseif ($removePicture) {
            $data['profile_picture'] = null;
        } else {
            unset($data['profile_picture']);
        }
        $user->update($data);
        if (array_key_exists('profile_picture', $data) && $previousPicture) {
            \App\Support\ProfilePhotoStorage::deleteOld($previousPicture, 'users/profile_pictures');
        }
        return response()->json(['message' => 'Account updated.', 'user' => $user->fresh()]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate(['current_password' => ['required'], 'password' => ['required', 'string', 'min:8', 'confirmed']]);
        if (!Hash::check($data['current_password'], $request->user()->password)) return response()->json(['message' => 'Current password is incorrect.', 'errors' => ['current_password' => ['Current password is incorrect.']]], 422);
        $request->user()->update(['password' => Hash::make($data['password'])]);
        return response()->json(['message' => 'Password changed.']);
    }

    private function validateEmployee(Request $request, ?Employee $employee = null, bool $updating = false): array
    {
        // Existing media URLs belong to the employee record, not to the upload request.
        // Older admin edit forms could echo those URLs back as JSON and Laravel would
        // correctly reject them as non-file values. Ignore only non-upload media values;
        // real multipart uploads remain in the request file bag and are still validated.
        if (!$request->hasFile('profile_picture')) {
            $request->request->remove('profile_picture');
        }

        if (!$request->hasFile('upload_documents')) {
            $request->request->remove('upload_documents');
        }

        return $request->validate([
            'first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($employee?->user_id)],
            'password' => ['nullable', 'string', 'min:8'], 'phone' => ['nullable', 'string', 'max:60'],
            'timezone' => ['required', 'timezone'], 'location' => ['nullable', 'string', 'max:120'],
            'department_id' => ['nullable', 'exists:departments,id'], 'role_id' => ['nullable', 'exists:roles,id'],
            'employment_type_id' => ['nullable', 'exists:employment_types,id'], 'shift_type_id' => ['nullable', 'exists:shift_types,id'],
            'designation_id' => ['nullable', 'exists:designations,id'], 'employee_status_id' => ['nullable', 'exists:employee_statuses,id'],
            'expertise_id' => ['nullable', 'exists:expertises,id'], 'reporting_manager_id' => ['nullable', 'exists:reporting_managers,id'],
            'salary_pkr' => ['nullable', 'numeric', 'min:0'], 'salary_usd' => ['nullable', 'numeric', 'min:0'],
            'source_of_hire' => ['nullable', 'string', 'max:120'], 'date_of_joining' => ['nullable', 'date'],
            'probation_end_date' => ['nullable', 'date'], 'date_of_regularisation' => ['nullable', 'date'], 'break_allowed_hours' => ['nullable', 'numeric', 'min:0', 'max:12'],
            'gender' => ['nullable', Rule::in(['male','female','other'])], 'marital_status' => ['nullable', Rule::in(['single','married','divorced','widowed'])],
            'date_of_birth' => ['nullable', 'date'], 'age' => ['nullable', 'integer', 'min:16', 'max:100'], 'about_me_notes' => ['nullable', 'string', 'max:3000'],
            'profile_picture' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'upload_documents' => ['nullable', 'array', 'max:10'],
            'upload_documents.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx', 'max:5120'],
        ]);
    }

    private function employeePayload(array $data): array
    {
        return collect($data)->except(['password', 'profile_picture', 'upload_documents'])->merge(['email' => $data['email']])->all();
    }


    private function sendClientInvitation(User $user, Client $client): array
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $url = URL::temporarySignedRoute(
            'client.password.form',
            now()->addDay(),
            ['email' => $user->email, 'token' => $token]
        );

        $sent = false;
        $error = null;
        try {
            Mail::to($user->email)->send(new ClientSetPasswordMail($client, $url));
            $sent = true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            report($e);
        }

        return ['sent' => $sent, 'url' => $url, 'error' => $error];
    }

    private function employeeDocumentUrls(Employee $employee): array
    {
        $documents = Employee::documentPaths($employee->upload_documents);

        return collect($documents)->map(function (string $reference, int $index) use ($employee) {
            $storage = $this->employeeDocumentStorageReference($reference);
            if ($storage) {
                $name = rawurlencode(basename($storage['path']));
                return "/api/management/employees/{$employee->id}/documents/{$index}?name={$name}";
            }

            return Employee::documentUrls([$reference])[0] ?? null;
        })->filter()->values()->all();
    }

    private function employeeDocumentStorageReference(string $reference): ?array
    {
        $reference = trim($reference);
        if ($reference === '') return null;

        if (Str::startsWith($reference, 'private:')) {
            $path = ltrim(substr($reference, strlen('private:')), '/');
            return Str::startsWith($path, 'employees/documents/')
                ? ['disk' => 'local', 'path' => $path]
                : null;
        }

        $path = parse_url($reference, PHP_URL_PATH) ?: $reference;
        $path = ltrim((string) $path, '/');
        foreach (['public/storage/', 'storage/'] as $prefix) {
            if (Str::startsWith($path, $prefix)) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        return Str::startsWith($path, 'employees/documents/')
            ? ['disk' => 'public', 'path' => $path]
            : null;
    }

    private function invoiceHardDeleteBlockReason(Invoice $invoice): ?string
    {
        $status = strtolower(trim((string) ($invoice->status ?? 'draft'))) ?: 'draft';
        if ($status !== 'draft') {
            return 'Only draft invoices can be permanently deleted. Issued, paid, or void invoices must stay in the audit history.';
        }
        if ($invoice->getAttribute('sent_at')) {
            return 'This invoice has already been sent to the Doctor / Client and cannot be permanently deleted. Mark it void instead.';
        }

        return null;
    }

    private function hardDeleteInvoice(Invoice $invoice): void
    {
        $invoice->adjustments()->delete();
        $invoice->salaries()->delete();
        $invoice->delete();
    }

    private function storeEmployeeMedia(Request $request): array
    {
        $media = [];

        if ($request->hasFile('profile_picture')) {
            $path = $request->file('profile_picture')->store('employees/profile_pictures', 'public');
            $media['profile_picture'] = Storage::url($path);
        }

        if ($request->hasFile('upload_documents')) {
            $documents = [];
            foreach ($request->file('upload_documents') as $file) {
                $path = $file->store('employees/documents', 'local');
                $documents[] = 'private:'.$path;
            }
            $media['upload_documents'] = $documents;
        }

        return $media;
    }

    private function sendEmployeeInvitation(User $user, Employee $employee): array
    {
        $token = Password::broker()->createToken($user);
        $url = url('/reset-password/'.$token).'?email='.urlencode($user->email).'&invite=1';
        $sent = false;
        $error = null;
        try {
            Mail::to($user->email)->send(new EmployeeInvitationMail($employee, $url));
            $sent = true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            report($e);
        }

        return ['sent' => $sent, 'url' => $url, 'error' => $error];
    }

    private function normalizeScheduleRecurrence(array $data, ?EmployeeClientSchedule $existing = null): array
    {
        $repeat = (bool) ($data['repeat'] ?? false);
        if ($repeat) {
            $data['schedule_date'] = null;
            return $data;
        }

        if (!empty($data['schedule_date'])) {
            return $data;
        }

        // Legacy rows created before one-time dates existed stay weekly until the
        // operator explicitly selects a date or changes a repeating row to Once.
        if ($existing && !$existing->repeat && !$existing->schedule_date) {
            $data['schedule_date'] = null;
            return $data;
        }

        $client = Client::find($data['client_id'] ?? null);
        $timezone = $this->validTimezoneValue($client?->timezone ?: $this->timezones->businessTimezone(), $this->timezones->businessTimezone());
        $weekday = $this->normalizeScheduleWeekday($data['weekday'] ?? null);
        if (!$weekday) {
            return $data;
        }

        $dayNumbers = [
            'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
            'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6,
        ];
        $now = $this->clock->now($timezone);
        $daysAhead = ($dayNumbers[$weekday] - $now->dayOfWeek + 7) % 7;
        $data['schedule_date'] = $now->copy()->addDays($daysAhead)->toDateString();

        return $data;
    }

    private function validateSchedule(Request $request): array
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'], 'client_id' => ['required', 'exists:clients,id'],
            'weekday' => ['required', Rule::in(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'])],
            'schedule_date' => ['nullable', 'date'],
            'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i'],
            'break_allowed_hours' => ['required', 'numeric', 'min:0', 'max:12.59', function ($attribute, $value, $fail) {
                $formatted = number_format((float) $value, 2, '.', '');
                $minutes = (int) explode('.', $formatted, 2)[1];
                if ($minutes > 59) $fail('Break time must use H.MM format where MM is 00 to 59. Example: 0.30 = 30 minutes.');
            }],
            'timezone' => ['nullable', 'timezone'], 'time_basis' => ['nullable', Rule::in(['employee','doctor'])], 'enabled' => ['required', 'boolean'], 'repeat' => ['nullable', 'boolean'],
        ]);

        $employee = Employee::find($data['employee_id']);
        $client = Client::find($data['client_id']);

        if (!$employee?->timezone) {
            throw ValidationException::withMessages([
                'employee_id' => ['Selected VA / Employee must have a timezone before a schedule can be saved.'],
            ]);
        }

        if (!$client?->timezone) {
            throw ValidationException::withMessages([
                'client_id' => ['Selected Client / Doctor must have a timezone so the doctor-local time can be displayed.'],
            ]);
        }

        $employeeTimezone = $this->validTimezoneValue($employee->timezone, 'UTC');
        $clientTimezone = $this->validTimezoneValue($client->timezone, 'UTC');

        // New schedule forms are Doctor-local/canonical. Keep the employee-basis
        // conversion branch only for backward compatibility with older clients.
        if (($data['time_basis'] ?? 'doctor') === 'employee') {
            $weekday = $this->normalizeScheduleWeekday($data['weekday'] ?? null);
            if ($weekday) {
                $dayNumbers = [
                    'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
                    'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6,
                ];

                if (!empty($data['schedule_date'])) {
                    $baseDate = Carbon::parse($data['schedule_date'], $employeeTimezone)->startOfDay();
                } else {
                    $employeeNow = $this->clock->now($employeeTimezone);
                    $daysAhead = ($dayNumbers[$weekday] - $employeeNow->dayOfWeek + 7) % 7;
                    $baseDate = $employeeNow->copy()->addDays($daysAhead)->startOfDay();
                }

                $employeeStart = Carbon::parse($baseDate->toDateString().' '.$data['start_time'], $employeeTimezone);
                $employeeEnd = Carbon::parse($baseDate->toDateString().' '.$data['end_time'], $employeeTimezone);
                if ($employeeEnd->lte($employeeStart)) $employeeEnd->addDay();

                $doctorStart = $employeeStart->copy()->setTimezone($clientTimezone);
                $doctorEnd = $employeeEnd->copy()->setTimezone($clientTimezone);

                $data['weekday'] = $doctorStart->format('l');
                $data['start_time'] = $doctorStart->format('H:i');
                $data['end_time'] = $doctorEnd->format('H:i');
                if (!($data['repeat'] ?? false)) {
                    $data['schedule_date'] = $doctorStart->toDateString();
                }
            }
        }

        unset($data['time_basis']);
        $data['timezone'] = $clientTimezone;

        return $data;
    }

    private function decorateScheduleForDisplay(EmployeeClientSchedule $schedule): EmployeeClientSchedule
    {
        $employeeTimezone = $this->validTimezoneValue($schedule->employee?->timezone ?: 'UTC', 'UTC');
        $clientTimezone = $this->validTimezoneValue($schedule->client?->timezone ?: $schedule->timezone ?: 'UTC', 'UTC');
        $weekday = $this->normalizeScheduleWeekday($schedule->weekday);

        $schedule->setAttribute('employee_timezone', $employeeTimezone);
        $schedule->setAttribute('client_timezone', $clientTimezone);

        if (!$weekday) return $schedule;

        $dayNumbers = [
            'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
            'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6,
        ];
        $now = $this->clock->now($clientTimezone);
        $oneTime = !$schedule->repeat && $schedule->schedule_date;

        if ($oneTime) {
            $baseDate = Carbon::parse($schedule->schedule_date, $clientTimezone)->startOfDay();
            $daysAhead = null;
        } else {
            $target = $dayNumbers[$weekday];
            $daysAhead = ($target - $now->dayOfWeek + 7) % 7;
            $baseDate = $now->copy()->addDays($daysAhead)->startOfDay();
        }

        $start = Carbon::parse($baseDate->toDateString().' '.$schedule->start_time, $clientTimezone);
        $end = Carbon::parse($baseDate->toDateString().' '.$schedule->end_time, $clientTimezone);
        if ($end->lte($start)) $end->addDay();

        if (!$oneTime && $daysAhead === 0 && $now->gte($end)) {
            $start->addWeek();
            $end->addWeek();
        }

        $schedule->setAttribute('shift_starts_at', $start->copy()->setTimezone($employeeTimezone)->toIso8601String());
        $schedule->setAttribute('shift_ends_at', $end->copy()->setTimezone($employeeTimezone)->toIso8601String());
        $schedule->setAttribute('client_shift_starts_at', $start->copy()->setTimezone($clientTimezone)->toIso8601String());
        $schedule->setAttribute('client_shift_ends_at', $end->copy()->setTimezone($clientTimezone)->toIso8601String());
        $schedule->setAttribute('calculation_timezone', $clientTimezone);
        $schedule->setAttribute('employee_weekday', $start->copy()->setTimezone($employeeTimezone)->format('l'));
        $schedule->setAttribute('client_weekday', $start->copy()->setTimezone($clientTimezone)->format('l'));

        return $schedule;
    }

    private function validTimezoneValue(?string $timezone, string $fallback = 'UTC'): string
    {
        $timezone = trim((string) $timezone);
        if ($timezone === '') return $fallback;
        try {
            new DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    private function normalizeScheduleWeekday(mixed $value): ?string
    {
        $raw = trim((string) $value);
        foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day) {
            if (strcasecmp($raw, $day) === 0) return $day;
        }
        return null;
    }

    private function hoursBetween(string $start, string $end): string
    {
        $a = Carbon::createFromFormat('H:i', $start); $b = Carbon::createFromFormat('H:i', $end); if ($b->lte($a)) $b->addDay();
        return number_format($a->diffInMinutes($b) / 60, 2, '.', '');
    }


    private function assertReferenceAccess(Request $request, string $type, string $action = 'view'): void
    {
        abort_unless(Modules::allows($request->user(), Modules::referenceModule($type), $action), 403);
    }

    private function referenceModel(string $type): string
    {
        abort_unless(isset($this->referenceMap[$type]), 404);
        return $this->referenceMap[$type];
    }

    private function recalculateAttendance(Attendance $attendance): void
    {
        $this->attendanceBilling->recalculate($attendance);
    }

}
