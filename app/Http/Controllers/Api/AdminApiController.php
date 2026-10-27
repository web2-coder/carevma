<?php

namespace App\Http\Controllers\Api;

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
use App\Models\EmployeeSalary;
use App\Models\EmployeeStatus;
use App\Models\EmploymentType;
use App\Models\Expertise;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\ReportingManager;
use App\Models\Role;
use App\Models\Service;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\InvoiceGenerator;
use App\Services\FxRateService;
use App\Services\TrustedTimeService;
use App\Services\ClientTimezoneService;
use DateTimeZone;
use App\Mail\EmployeeInvitationMail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminApiController extends Controller
{
    public function __construct(
        private TrustedTimeService $clock,
        private ClientTimezoneService $timezones,
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

        if (!Schema::hasColumn('attendances', 'client_id') || !Schema::hasColumn('attendances', 'clock_in')) {
            $rows = Attendance::query()
                ->selectRaw('date, COUNT(*) as sessions, COALESCE(SUM(total_minutes),0) as minutes')
                ->whereBetween('date', [$minDate, $maxDate])
                ->groupBy('date')
                ->get();
            foreach ($rows as $row) {
                $key = optional($row->date)->toDateString() ?: (string) $row->date;
                if (isset($result[$key])) $result[$key] = ['sessions' => (int) $row->sessions, 'minutes' => (int) $row->minutes];
            }
            return $result;
        }

        $clients = Client::query()->get(['id', 'timezone'])->keyBy('id');
        // All IANA zones are within this small UTC envelope around the requested local dates.
        $scanStart = Carbon::parse($minDate, 'UTC')->subDays(2)->startOfDay();
        $scanEnd = Carbon::parse($maxDate, 'UTC')->addDays(2)->endOfDay();

        Attendance::query()
            ->select(['id', 'client_id', 'date', 'clock_in', 'total_minutes'])
            ->whereNotNull('client_id')
            ->whereBetween('clock_in', [$scanStart, $scanEnd])
            ->orderBy('id')
            ->cursor()
            ->each(function (Attendance $row) use (&$result, $clients) {
                $client = $clients->get($row->client_id);
                if (!$client || !$row->clock_in) return;
                $localDate = $this->timezones->localDate($row->clock_in, $this->timezones->forClient($client));
                if (!$localDate || !isset($result[$localDate])) return;
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

        $activeQuery = Attendance::query()->whereNull('clock_out');
        $pendingOvertime = Schema::hasColumn('attendances', 'overtime_status')
            ? Attendance::where('overtime_status', 'pending')->count()
            : Attendance::where('overtime', '>', 0)->count();

        $birthdaysToday = Employee::query()
            ->whereNotNull('date_of_birth')
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

        // CareVMA billing cycle: 25th through the 26th of the following month.
        // Drafts are generated on the 27th so the full 26th can be included.
        $completedCycleEnd = $appNow->copy()->day(26);
        if ($appNow->day >= 27) {
            $completedCycleEnd->addMonthNoOverflow()->day(26);
        }
        $cycleStart = $completedCycleEnd->copy()->subMonthNoOverflow()->day(25)->toDateString();
        $cycleEnd = $completedCycleEnd->copy()->day(26)->toDateString();
        $scheduledClose = $completedCycleEnd->copy()->addDay()->setTime(0, 15, 0);

        $cycleInvoices = Invoice::query()
            ->where('auto_generated', true)
            ->whereDate('period_start', $cycleStart)
            ->whereDate('period_end', $cycleEnd);
        $cycleInvoiceIds = (clone $cycleInvoices)->pluck('id');
        $cycleCount = $cycleInvoiceIds->count();
        $cycleAmount = (float) (clone $cycleInvoices)->sum('total_amount');
        $cycleVaCount = $cycleInvoiceIds->isEmpty() ? 0 : EmployeeSalary::query()
            ->whereIn('invoice_id', $cycleInvoiceIds)
            ->distinct('employee_id')
            ->count('employee_id');
        $lastGeneratedAt = (clone $cycleInvoices)->max('generated_at');

        $financeRangeStart = $appNow->copy()->subMonthsNoOverflow(5)->startOfMonth();
        $financeRangeEnd = $appNow->copy()->endOfMonth();
        $financeDaily = $this->financeDailySeries($financeRangeStart, $financeRangeEnd);
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
            'metrics' => [
                'employees' => Employee::count(),
                'clients' => Client::count(),
                'active' => $activeQuery->count(),
                'today_sessions' => ($attendanceSummaries[$today]['sessions'] ?? 0),
                'pending_overtime' => $pendingOvertime,
                'invoices' => Invoice::count(),
            ],
            'trend' => $trend,
            'recent_attendance' => Attendance::with([
                'employee:id,first_name,last_name,profile_picture,timezone',
                'client:id,name,business_name,timezone',
            ])->latest('id')->limit(8)->get()->map(function (Attendance $row) {
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
            'auto_invoice_cycle' => [
                'period_start' => $cycleStart,
                'period_end' => $cycleEnd,
                'scheduled_for' => $scheduledClose->toIso8601String(),
                'generated_count' => $cycleCount,
                'billed_va_count' => $cycleVaCount,
                'total_amount' => round($cycleAmount, 2),
                'last_generated_at' => $lastGeneratedAt,
                'status' => $cycleCount > 0 ? 'generated' : ($appNow->lt($scheduledClose) ? 'scheduled' : 'no_billable_invoices'),
            ],
            'finance' => $finance,
            'finance_trend' => $financeTrend,
        ]);
    }


    public function liveCount(Request $request)
    {
        return response()->json([
            'total' => Attendance::query()->whereNull('clock_out')->count(),
        ]);
    }

    public function liveUsers(Request $request)
    {
        $serverNow = $this->clock->now('UTC');

        $sessions = Attendance::query()
            ->with([
                'employee:id,user_id,first_name,last_name,email,employee_code,profile_picture,timezone',
                'employee.user:id,name,email,last_seen_at',
                'client:id,name,business_name,timezone',
                'schedule:id,weekday,start_time,end_time,timezone',
                'openBreak:id,attendance_id,start_time,end_time',
            ])
            ->whereNull('clock_out')
            ->latest('clock_in')
            ->get()
            ->map(function (Attendance $attendance) use ($serverNow) {
                $openBreak = $attendance->openBreak;

                /*
                 * Attendance timestamps are stored as UTC database values.  Do not let
                 * Laravel's application timezone reinterpret a raw UTC value before we
                 * calculate elapsed time; doing that creates the exact +5 hour error seen
                 * when the app timezone is Asia/Karachi.
                 */
                $clockInUtc = null;
                $rawClockIn = $attendance->getRawOriginal('clock_in');
                if ($rawClockIn) {
                    try {
                        $clockInUtc = Carbon::parse((string) $rawClockIn, 'UTC')->utc();
                    } catch (\Throwable $e) {
                        $clockInUtc = null;
                    }
                }

                $elapsedSeconds = 0;
                if ($clockInUtc) {
                    $elapsedSeconds = max(0, $serverNow->timestamp - $clockInUtc->timestamp);
                }

                $currentBreakSeconds = 0;
                if ($openBreak) {
                    $rawBreakStart = method_exists($openBreak, 'getRawOriginal')
                        ? $openBreak->getRawOriginal('start_time')
                        : $openBreak->start_time;

                    if ($rawBreakStart) {
                        try {
                            $breakStartUtc = Carbon::parse((string) $rawBreakStart, 'UTC')->utc();
                            $currentBreakSeconds = max(0, $serverNow->timestamp - $breakStartUtc->timestamp);
                        } catch (\Throwable $e) {
                            $currentBreakSeconds = 0;
                        }
                    }
                }

                $completedBreakSeconds = (int) ($attendance->break_taken ?? 0);

                $vaTimezone = $attendance->employee?->timezone ?: 'UTC';
                $doctorTimezone = $attendance->client?->timezone
                    ?: $attendance->timezone_snapshot
                    ?: $attendance->schedule?->timezone
                    ?: null;
                $calculationTimezone = $attendance->timezone_snapshot
                    ?: $doctorTimezone
                    ?: $attendance->schedule?->timezone
                    ?: $vaTimezone;

                $clockInVaLabel = null;
                $clockInDoctorLabel = null;
                $clockInUtcIso = null;

                if ($clockInUtc) {
                    $clockInUtcIso = $clockInUtc->toIso8601ZuluString();
                    try {
                        $clockInVaLabel = $clockInUtc->copy()->setTimezone($vaTimezone)->format('M d, Y, g:i A');
                    } catch (\Throwable $e) {
                        $clockInVaLabel = $clockInUtc->format('M d, Y, g:i A').' UTC';
                    }

                    if ($doctorTimezone) {
                        try {
                            $clockInDoctorLabel = $clockInUtc->copy()->setTimezone($doctorTimezone)->format('M d, Y, g:i A');
                        } catch (\Throwable $e) {
                            $clockInDoctorLabel = null;
                        }
                    }
                }

                $scheduleDoctorLabel = null;
                $scheduleVaLabel = null;
                if ($attendance->schedule && $attendance->date) {
                    try {
                        $start = Carbon::parse($attendance->date->format('Y-m-d').' '.$attendance->schedule->start_time, $calculationTimezone);
                        $end = Carbon::parse($attendance->date->format('Y-m-d').' '.$attendance->schedule->end_time, $calculationTimezone);
                        if ($end->lte($start)) $end->addDay();
                        $scheduleDoctorLabel = $start->copy()->setTimezone($doctorTimezone ?: $calculationTimezone)->format('D, M d, g:i A').' – '.$end->copy()->setTimezone($doctorTimezone ?: $calculationTimezone)->format('D, M d, g:i A');
                        $scheduleVaLabel = $start->copy()->setTimezone($vaTimezone)->format('D, M d, g:i A').' – '.$end->copy()->setTimezone($vaTimezone)->format('D, M d, g:i A');
                    } catch (\Throwable $e) {
                        $scheduleDoctorLabel = null;
                        $scheduleVaLabel = null;
                    }
                }

                return [
                    'id' => $attendance->id,
                    'employee' => $attendance->employee,
                    'client' => $attendance->client,
                    'schedule' => $attendance->schedule,
                    'clock_in' => $clockInUtcIso,
                    'clock_in_utc' => $clockInUtcIso,
                    'clock_in_va_label' => $clockInVaLabel,
                    'clock_in_doctor_label' => $clockInDoctorLabel,
                    'elapsed_seconds' => $elapsedSeconds,
                    'timezone' => $vaTimezone,
                    'va_timezone' => $vaTimezone,
                    'doctor_timezone' => $doctorTimezone,
                    'calculation_timezone' => $calculationTimezone,
                    'schedule_doctor_label' => $scheduleDoctorLabel,
                    'schedule_va_label' => $scheduleVaLabel,
                    'late_minutes' => (int) ($attendance->late_minutes ?? 0),
                    'break_limit' => $attendance->break_limit,
                    'completed_break_seconds' => $completedBreakSeconds,
                    'current_break_seconds' => $currentBreakSeconds,
                    'total_break_seconds' => $completedBreakSeconds + $currentBreakSeconds,
                    'is_on_break' => (bool) $openBreak,
                    'status' => $openBreak ? 'break' : 'working',
                ];
            })
            ->values();

        return response()->json([
            'server_now' => $serverNow->toIso8601ZuluString(),
            'time_source' => $this->clock->source(),
            'total' => $sessions->count(),
            'working' => $sessions->where('status', 'working')->count(),
            'on_break' => $sessions->where('status', 'break')->count(),
            'sessions' => $sessions,
        ]);
    }

    public function employees(Request $request)
    {
        $query = Employee::with(['user:id,name,email,role,last_seen_at', 'department', 'designation', 'employmentType', 'shiftType', 'employeeStatus', 'expertise', 'reportingManager']);
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%");
            });
        }
        return response()->json($query->latest('id')->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function employee(Employee $employee)
    {
        return response()->json($employee->load(['user', 'department', 'role', 'employmentType', 'shiftType', 'designation', 'employeeStatus', 'expertise', 'reportingManager']));
    }

    public function storeEmployee(Request $request)
    {
        $data = $this->validateEmployee($request);

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

        return response()->json([
            'message' => $invite['sent']
                ? 'VA created and activation invitation sent.'
                : 'VA created. Email delivery failed, so use the activation link below or resend the invitation.',
            'employee' => $employee->fresh(),
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
        $data = $this->validateEmployee($request, $employee, true);
        return DB::transaction(function () use ($data, $employee) {
            $payload = $this->employeePayload($data);
            $employee->update($payload);
            $userData = ['name' => trim($employee->first_name.' '.$employee->last_name), 'email' => $employee->email];
            if (!empty($data['password'])) {
                $userData['password'] = Hash::make($data['password']);
                $employee->update(['password' => $userData['password']]);
            }
            $employee->user?->update($userData);
            return response()->json(['message' => 'Employee updated.', 'employee' => $employee->fresh()]);
        });
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
        return response()->json($query->latest('id')->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function storeHrUser(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'role' => 'hr']);
        return response()->json(['message' => 'HR account created.', 'user' => $user], 201);
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
        $query = Client::with(['user:id,name,email,role', 'service', 'contractType']);
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('business_name', 'like', "%{$search}%"));
        }
        return response()->json($query->latest('id')->paginate(min((int) $request->query('per_page', 25), 100)));
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
            'password' => ['required', 'string', 'min:8'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:60'],
            'country' => ['nullable', 'string', 'max:100'],
            'timezone' => ['required', 'timezone'],
            'per_hour_charges' => ['nullable', 'numeric', 'min:0'],
            'contract_type_id' => ['nullable', 'exists:contract_types,id'],
            'service_id' => ['nullable', 'exists:services,id'],
            'ring_center' => ['nullable', 'boolean'],
        ]);
        return DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'role' => 'client']);
            $client = Client::create(array_merge(collect($data)->except('password')->all(), ['user_id' => $user->id]));
            return response()->json(['message' => 'Client created.', 'client' => $client], 201);
        });
    }

    public function updateClient(Request $request, Client $client)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($client->user_id)],
            'business_name' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:60'],
            'country' => ['nullable', 'string', 'max:100'],
            'timezone' => ['required', 'timezone'],
            'per_hour_charges' => ['nullable', 'numeric', 'min:0'],
            'contract_type_id' => ['nullable', 'exists:contract_types,id'],
            'service_id' => ['nullable', 'exists:services,id'],
            'ring_center' => ['nullable', 'boolean'],
        ]);
        DB::transaction(function () use ($data, $client) {
            $previousTimezone = $client->timezone;
            $client->update($data);
            $client->user?->update(['name' => $data['name'], 'email' => $data['email']]);

            // Schedule wall-clock values are Doctor-local/canonical. If the client changes
            // timezone, keep the same wall-clock schedule and synchronize legacy metadata so
            // every downstream path resolves the new IANA timezone consistently.
            if ($previousTimezone !== $client->timezone && Schema::hasColumn('employee_client_schedules', 'timezone')) {
                EmployeeClientSchedule::where('client_id', $client->id)->update(['timezone' => $client->timezone]);
            }
        });
        return response()->json(['message' => 'Client updated.', 'client' => $client->fresh()]);
    }

    public function destroyClient(Client $client)
    {
        $hasAttendance = Schema::hasColumn('attendances', 'client_id') && Attendance::where('client_id', $client->id)->exists();
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

    public function schedules(Request $request)
    {
        $query = EmployeeClientSchedule::with([
            'employee:id,first_name,last_name,employee_code,email,timezone',
            'client:id,name,business_name,timezone',
        ]);
        if ($request->filled('employee_id')) $query->where('employee_id', $request->integer('employee_id'));
        if ($request->filled('client_id')) $query->where('client_id', $request->integer('client_id'));

        $query->orderByRaw("FIELD(weekday,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')")
            ->orderBy('start_time')
            ->orderBy('id');

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
            $paginator = $query->paginate(min(max((int) $request->query('per_page', 50), 10), 100));
            $decorate($paginator->getCollection());
            return response()->json($paginator);
        }

        $rows = $query->get();
        $decorate($rows);
        return response()->json($rows);
    }

    public function storeSchedule(Request $request)
    {
        $data = $this->validateSchedule($request);
        $data = $this->normalizeScheduleRecurrence($data);
        $data['no_of_hours'] = $this->hoursBetween($data['start_time'], $data['end_time']);
        $schedule = EmployeeClientSchedule::create($data);
        if ($request->filled('per_hour_rate')) {
            ClientEmployeeRate::updateOrCreate(['client_id' => $data['client_id'], 'employee_id' => $data['employee_id']], ['per_hour_rate' => $request->input('per_hour_rate')]);
        }
        $schedule->load(['employee:id,first_name,last_name,employee_code,email,timezone', 'client:id,name,business_name,timezone']);
        $this->decorateScheduleForDisplay($schedule);
        $schedule->setAttribute('per_hour_rate', ClientEmployeeRate::where('client_id', $schedule->client_id)->where('employee_id', $schedule->employee_id)->value('per_hour_rate'));
        return response()->json(['message' => 'Schedule created.', 'schedule' => $schedule], 201);
    }

    public function updateSchedule(Request $request, EmployeeClientSchedule $schedule)
    {
        $data = $this->validateSchedule($request);
        $data = $this->normalizeScheduleRecurrence($data, $schedule);
        $data['no_of_hours'] = $this->hoursBetween($data['start_time'], $data['end_time']);
        $schedule->update($data);
        if ($request->filled('per_hour_rate')) {
            ClientEmployeeRate::updateOrCreate(['client_id' => $data['client_id'], 'employee_id' => $data['employee_id']], ['per_hour_rate' => $request->input('per_hour_rate')]);
        }
        $schedule = $schedule->fresh(['employee:id,first_name,last_name,employee_code,email,timezone', 'client:id,name,business_name,timezone']);
        $this->decorateScheduleForDisplay($schedule);
        $schedule->setAttribute('per_hour_rate', ClientEmployeeRate::where('client_id', $schedule->client_id)->where('employee_id', $schedule->employee_id)->value('per_hour_rate'));
        return response()->json(['message' => 'Schedule updated.', 'schedule' => $schedule]);
    }

    public function destroySchedule(EmployeeClientSchedule $schedule)
    {
        $schedule->delete();
        return response()->json(['message' => 'Schedule deleted.']);
    }

    public function attendance(Request $request)
    {
        $query = Attendance::with(['employee:id,first_name,last_name,employee_code,timezone,profile_picture', 'breaks']);
        if (Schema::hasColumn('attendances', 'client_id')) $query->with('client:id,name,business_name,timezone');
        if ($request->filled('employee_id')) $query->where('employee_id', $request->integer('employee_id'));
        $selectedClientId = $request->filled('client_id') && Schema::hasColumn('attendances', 'client_id')
            ? $request->integer('client_id')
            : null;
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
        $attendance = $query->latest('date')->latest('id')->paginate(min((int) $request->query('per_page', 25), 100));
        $attendance->through(function (Attendance $row) {
            $clientZone = $this->timezones->forAttendance($row, true);
            $employeeZone = $this->timezones->valid($row->employee?->timezone, 'UTC');
            $row->setAttribute('client_timezone', $clientZone);
            $row->setAttribute('employee_timezone', $employeeZone);
            $row->setAttribute('clock_in_client', $this->timezones->isoInZone($row->clock_in, $clientZone));
            $row->setAttribute('clock_out_client', $this->timezones->isoInZone($row->clock_out, $clientZone));
            $row->setAttribute('clock_in_employee', $this->timezones->isoInZone($row->clock_in, $employeeZone));
            $row->setAttribute('clock_out_employee', $this->timezones->isoInZone($row->clock_out, $employeeZone));
            return $row;
        });
        return response()->json($attendance);
    }

    private function applyAttendanceLocalDateFilter($query, ?string $from, ?string $to, ?int $clientId = null): void
    {
        if (!$from && !$to) {
            return;
        }

        if (!Schema::hasColumn('attendances', 'client_id') || !Schema::hasColumn('attendances', 'clock_in')) {
            if ($from) $query->whereDate('date', '>=', $from);
            if ($to) $query->whereDate('date', '<=', $to);
            return;
        }

        $clients = $clientId
            ? Client::query()->whereKey($clientId)->get(['id', 'timezone'])
            : Client::query()->get(['id', 'timezone']);

        $query->where(function ($outer) use ($clients, $from, $to) {
            foreach ($clients as $client) {
                [$startUtc, $endUtc] = $this->timezones->utcRangeForLocalFilters($from, $to, $client);
                $outer->orWhere(function ($clientQuery) use ($client, $startUtc, $endUtc) {
                    $clientQuery->where('client_id', $client->id);
                    if ($startUtc) $clientQuery->where('clock_in', '>=', $startUtc);
                    if ($endUtc) $clientQuery->where('clock_in', '<=', $endUtc);
                });
            }

            // Keep legacy/unassigned attendance queryable without pretending it has a client timezone.
            $outer->orWhere(function ($legacy) use ($from, $to) {
                $legacy->whereNull('client_id');
                if ($from) $legacy->whereDate('date', '>=', $from);
                if ($to) $legacy->whereDate('date', '<=', $to);
            });
        });
    }

    public function changeRequests(Request $request)
    {
        $query = AttendanceChangeRequest::with(['employee:id,first_name,last_name,employee_code', 'attendance']);
        if ($request->filled('status')) $query->where('status', $request->input('status'));
        return response()->json($query->latest('id')->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function reviewChangeRequest(Request $request, AttendanceChangeRequest $changeRequest)
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => ['nullable', 'string', 'max:1000']]);
        if ($changeRequest->status !== 'pending') return response()->json(['message' => 'This request has already been reviewed.'], 422);

        DB::transaction(function () use ($changeRequest, $data, $request) {
            if ($data['decision'] === 'approved') {
                $attendance = $changeRequest->attendance;
                $date = Carbon::parse($changeRequest->date)->toDateString();
                $timezone = $this->timezones->forAttendance($attendance, false);
                $requestedIn = Carbon::parse($date.' '.$changeRequest->requested_clock_in, $timezone);
                $requestedOut = Carbon::parse($date.' '.$changeRequest->requested_clock_out, $timezone);
                if ($requestedOut->lte($requestedIn)) $requestedOut->addDay();
                $attendance->clock_in = $requestedIn->utc();
                $attendance->clock_out = $requestedOut->utc();
                $attendance->save();
                $this->recalculateAttendance($attendance);
            }
            $update = ['status' => $data['decision']];
            if (Schema::hasColumn('attendance_change_requests', 'reviewed_by')) {
                $update += ['reviewed_by' => $request->user()->id, 'reviewed_at' => $this->clock->now('UTC'), 'review_note' => $data['note'] ?? null];
            }
            $changeRequest->update($update);
        });
        return response()->json(['message' => 'Change request '.$data['decision'].'.']);
    }

    public function overtime(Request $request)
    {
        $query = Attendance::with('employee:id,first_name,last_name,employee_code')->where('overtime', '>', 0);
        if (Schema::hasColumn('attendances', 'overtime_status')) $query->whereIn('overtime_status', ['pending', 'approved', 'rejected']);
        return response()->json($query->latest('date')->paginate(25));
    }

    public function reviewOvertime(Request $request, Attendance $attendance)
    {
        if (!Schema::hasColumn('attendances', 'overtime_status')) return response()->json(['message' => 'Run the CareVMA upgrade migration first.'], 422);
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
        return response()->json(['message' => 'Overtime review saved.']);
    }

    public function companyFinance(Request $request)
    {
        $view = in_array($request->query('view'), ['day', 'month', 'year'], true)
            ? $request->query('view')
            : 'month';
        $anchor = $request->filled('date')
            ? Carbon::parse($request->query('date'), $this->timezones->businessTimezone())
            : $this->clock->now($this->timezones->businessTimezone());

        [$from, $to] = match ($view) {
            'day' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
            'year' => [$anchor->copy()->startOfYear(), $anchor->copy()->endOfYear()],
            default => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
        };

        $daily = $this->financeDailySeries($from, $to);
        $summary = $this->financeSummaryFromDaily($daily, $from, $to);

        if ($view === 'year') {
            $trend = collect(range(1, 12))->map(function ($month) use ($anchor, $daily) {
                $point = $anchor->copy()->month($month);
                $snapshot = $this->financeSummaryFromDaily($daily, $point->copy()->startOfMonth(), $point->copy()->endOfMonth());
                return ['label' => $point->format('M'), 'income' => $snapshot['incoming'], 'outgoing' => $snapshot['outgoing'], 'net' => $snapshot['net']];
            });
        } elseif ($view === 'month') {
            $trend = collect(range(1, $anchor->daysInMonth))->map(function ($day) use ($anchor, $daily) {
                $point = $anchor->copy()->day($day);
                $snapshot = $daily[$point->toDateString()] ?? $this->emptyFinanceSnapshot();
                return ['label' => (string) $day, 'income' => $snapshot['incoming'], 'outgoing' => $snapshot['outgoing'], 'net' => $snapshot['net']];
            });
        } else {
            $trend = collect(range(6, 0))->map(function ($offset) use ($anchor, $daily) {
                $point = $anchor->copy()->subDays($offset);
                $snapshot = $daily[$point->toDateString()] ?? $this->emptyFinanceSnapshot();
                return ['label' => $point->format('D'), 'income' => $snapshot['incoming'], 'outgoing' => $snapshot['outgoing'], 'net' => $snapshot['net']];
            })->push((function () use ($anchor, $daily) {
                $snapshot = $daily[$anchor->toDateString()] ?? $this->emptyFinanceSnapshot();
                return ['label' => $anchor->format('D'), 'income' => $snapshot['incoming'], 'outgoing' => $snapshot['outgoing'], 'net' => $snapshot['net']];
            })());
        }

        $entries = Schema::hasTable('company_finance_entries')
            ? CompanyFinanceEntry::query()
                ->with('creator:id,name')
                ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
                ->latest('entry_date')
                ->latest('id')
                ->limit(100)
                ->get()
            : collect();

        return response()->json([
            'view' => $view,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => $summary,
            'trend' => $trend,
            'entries' => $entries,
        ]);
    }

    public function storeCompanyFinanceEntry(Request $request)
    {
        abort_unless(Schema::hasTable('company_finance_entries'), 422, 'Run php artisan migrate first.');

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

        if (Schema::hasTable('company_finance_entries')) {
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

    public function invoices(Request $request)
    {
        return response()->json(Invoice::with('client:id,name,business_name,timezone')->latest('id')->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function generateInvoice(Request $request, InvoiceGenerator $generator)
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        if (Invoice::where('client_id', $data['client_id'])
            ->where('period_start', $data['period_start'])
            ->where('period_end', $data['period_end'])
            ->exists()) {
            return response()->json(['message' => 'An invoice already exists for this client and exact billing period.'], 422);
        }

        $client = Client::findOrFail($data['client_id']);
        $invoice = $generator->generate($client, $data['period_start'], $data['period_end'], false);

        if (!$invoice) {
            return response()->json(['message' => 'No completed, unbilled attendance exists for this client in the selected client-local date range.'], 422);
        }

        return response()->json([
            'message' => 'Invoice generated as an editable draft.',
            'invoice' => $invoice,
        ], 201);
    }

    public function invoice(Invoice $invoice)
    {
        return response()->json($invoice->load([
            'client:id,name,business_name,timezone',
            'salaries.employee:id,first_name,last_name,employee_code',
            'adjustments.employee:id,first_name,last_name,employee_code',
        ]));
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

        DB::transaction(function () use ($invoice, $data, $request) {
            $totalHours = 0;
            $totalAmount = 0;

            foreach ($data['lines'] as $lineData) {
                $line = $invoice->salaries()->whereKey($lineData['id'])->firstOrFail();
                $line->update([
                    'total_hours' => round((float) $lineData['total_hours'], 2),
                    'total_late' => (int) ($lineData['total_late'] ?? 0),
                    'total_overtime' => (float) ($lineData['total_overtime'] ?? 0),
                    'salary_amount' => round((float) $lineData['salary_amount'], 2),
                    'period_start' => $data['period_start'],
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

            $invoice->update([
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'status' => $data['status'] ?? $invoice->status ?? 'draft',
                'total_hours' => round($totalHours, 2),
                'total_amount' => $grandTotal,
                'edited_by' => $request->user()->id,
                'edited_at' => $this->clock->now('UTC'),
            ]);
        });

        $fresh = $invoice->fresh([
            'client:id,name,business_name,timezone',
            'salaries.employee:id,first_name,last_name,employee_code',
            'adjustments.employee:id,first_name,last_name,employee_code',
        ]);
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
        $this->assertReferenceAccess($request, $type);
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
        $this->assertReferenceAccess($request, $type);
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
        $this->assertReferenceAccess($request, $type);
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

        return response()->json(array_merge([
            'employees' => Employee::orderBy('first_name')->get(['id', 'employee_code', 'first_name', 'last_name', 'timezone']),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'business_name', 'timezone', 'per_hour_charges']),
        ], $references, [
            'business_timezone' => $this->timezones->businessTimezone(),
        ]));
    }

    public function notifications()
    {
        return response()->json(Notification::latest('id')->paginate(30));
    }

    public function markNotificationsRead()
    {
        Notification::where('is_read', false)->update(['is_read' => true]);
        return response()->json(['message' => 'Notifications marked as read.']);
    }

    public function updateAccount(Request $request)
    {
        $user = $request->user();
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)]]);
        $user->update($data);
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
            'date_of_regularisation' => ['nullable', 'date'], 'break_allowed_hours' => ['nullable', 'numeric', 'min:0', 'max:12'],
            'gender' => ['nullable', Rule::in(['male','female','other'])], 'marital_status' => ['nullable', 'string', 'max:50'],
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
                $documents[] = Storage::url($file->store('employees/documents', 'public'));
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

        try {
            Mail::to($user->email)->send(new EmployeeInvitationMail($employee, $url));
            $sent = true;
        } catch (\Throwable $exception) {
            report($exception);
        }

        return ['sent' => $sent, 'url' => $url];
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

        // Client Profile assignment forms are entered in the VA's local timezone.
        // Convert that VA-local wall clock to the exact Client / Doctor local wall clock
        // before saving. The stored schedule then remains Doctor-local/canonical for
        // check-in windows, lateness, overtime and billing calculations.
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


    private function assertReferenceAccess(Request $request, string $type): void
    {
        if ($request->user()?->role === 'hr' && in_array($type, ['services', 'contracts'], true)) {
            abort(403, 'Client configuration is Admin-only.');
        }
    }

    private function referenceModel(string $type): string
    {
        abort_unless(isset($this->referenceMap[$type]), 404);
        return $this->referenceMap[$type];
    }

    private function recalculateAttendance(Attendance $attendance): void
    {
        if (!$attendance->clock_in || !$attendance->clock_out) return;
        $schedule = Schema::hasColumn('attendances', 'schedule_id') && $attendance->schedule_id ? EmployeeClientSchedule::find($attendance->schedule_id) : null;
        $tz = $attendance->timezone_snapshot ?? $schedule?->timezone ?? $attendance->employee?->timezone ?? 'UTC';
        $in = $attendance->clock_in->copy()->setTimezone($tz)->startOfMinute(); $out = $attendance->clock_out->copy()->setTimezone($tz)->startOfMinute();
        $regularStart = $in->copy(); $regularEnd = $out->copy(); $overtime = 0; $late = 0;
        if ($attendance->shift_start_snapshot && $attendance->shift_end_snapshot) {
            $shiftStart = $attendance->shift_start_snapshot->copy()->setTimezone($tz)->startOfMinute();
            $shiftEnd = $attendance->shift_end_snapshot->copy()->setTimezone($tz)->startOfMinute();
            if ($regularStart->lt($shiftStart)) $regularStart = $shiftStart->copy();
            if ($regularEnd->gt($shiftEnd)) { $overtime = (int) $shiftEnd->diffInMinutes($regularEnd); $regularEnd = $shiftEnd->copy(); }
            if ($in->gt($shiftStart)) $late = (int) $shiftStart->diffInMinutes($in);
        } elseif ($schedule) {
            $shiftStart = Carbon::parse($attendance->date->format('Y-m-d').' '.$schedule->start_time, $tz)->startOfMinute();
            $shiftEnd = Carbon::parse($attendance->date->format('Y-m-d').' '.$schedule->end_time, $tz)->startOfMinute(); if ($shiftEnd->lte($shiftStart)) $shiftEnd->addDay();
            if ($regularStart->lt($shiftStart)) $regularStart = $shiftStart->copy();
            if ($regularEnd->gt($shiftEnd)) { $overtime = (int) $shiftEnd->diffInMinutes($regularEnd); $regularEnd = $shiftEnd->copy(); }
            if ($in->gt($shiftStart)) $late = (int) $shiftStart->diffInMinutes($in);
        }
        $breakMinutes = (int) floor(($attendance->break_taken ?? 0) / 60);
        $attendance->total_minutes = max(0, (int) $regularStart->diffInMinutes($regularEnd) - $breakMinutes);
        $attendance->is_late = $late > 0;
        $attendance->late_minutes = $late;
        $attendance->overtime = $overtime;
        if (Schema::hasColumn('attendances', 'overtime_status')) {
            $attendance->overtime_status = $overtime > 0 ? 'pending' : 'none';
            $attendance->approved_overtime_minutes = 0;
            $attendance->overtime_amount = null;
            $attendance->overtime_approved_by = null;
            $attendance->overtime_approved_at = null;
        }
        $attendance->save();
    }
}
