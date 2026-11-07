<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeClientSchedule;
use App\Models\EmployeeSalary;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class CareVmaAiController extends Controller
{
    private const DATASETS = [
        'employees',
        'doctors',
        'attendance',
        'schedules',
        'invoices',
        'salaries',
        'probation',
        'overtime',
        'live_workforce',
        'employee_full_record',
        'system_summary',
        'records',
    ];

    /**
     * Safe generic tables available to the read-only fallback reporter.
     * Sensitive authentication fields are stripped again at column level.
     */
    private const SAFE_TABLES = [
        'users',
        'employees',
        'clients',
        'attendances',
        'employee_breaks',
        'employee_client_schedules',
        'employee_salaries',
        'invoices',
        'invoice_adjustments',
        'work_sessions',
        'work_session_breaks',
        'attendance_change_requests',
        'schedule_change_requests',
        'probations',
        'probation_histories',
        'company_finance_entries',
        'notifications',
        'departments',
        'designations',
        'employment_types',
        'employee_statuses',
        'roles',
        'services',
        'contract_types',
        'expertises',
        'reporting_managers',
    ];

    private const DENIED_COLUMNS = [
        'password',
        'remember_token',
        'google_id',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_token',
        'api_key',
        'secret',
        'token',
        'activation_token',
        'invite_token',
        'invitation_token',
    ];

    public function ask(Request $request)
    {
        $this->authorizeAi($request);

        $data = $request->validate([
            'prompt' => 'required|string|max:5000',
        ]);

        $prompt = trim($data['prompt']);
        $history = $this->conversationContext((int) $request->user()->id);
        $providerReady = filled((string) config('carevma_ai.api_key'));
        $model = (string) config('carevma_ai.model', 'gpt-6.1-sol');

        try {
            $plan = $providerReady
                ? $this->planWithOpenAi($prompt, $history)
                : $this->fallbackPlan($prompt);
        } catch (Throwable $e) {
            Log::warning('CareVMA AI planner failed; using safe fallback.', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            $plan = $this->fallbackPlan($prompt);
            $providerReady = false;
        }

        $plan = $this->normalizePlan($plan);
        $reports = [];

        foreach ($plan['queries'] as $query) {
            try {
                $reports[] = $this->executeQuery($query);
            } catch (Throwable $e) {
                Log::error('CareVMA AI report query failed.', [
                    'dataset' => $query['dataset'] ?? null,
                    'user_id' => $request->user()->id,
                    'error' => $e->getMessage(),
                ]);
                $reports[] = [
                    'dataset' => $query['dataset'] ?? 'unknown',
                    'title' => 'Report unavailable',
                    'summary' => 'This part of the request could not be read safely from the current database schema.',
                    'rows' => [],
                    'meta' => ['error' => true],
                ];
            }
        }

        try {
            $assistantMessage = $providerReady
                ? $this->answerWithOpenAi($prompt, $reports, $history)
                : $this->deterministicAnswer($reports, false);
        } catch (Throwable $e) {
            Log::warning('CareVMA AI answer formatting failed.', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            $assistantMessage = $this->deterministicAnswer($reports, true);
        }

        if (!$providerReady) {
            $assistantMessage .= "\n\nAI provider is not configured/reachable, so this response used the basic safe parser. Add OPENAI_API_KEY to enable Roman Urdu/English conversational understanding.";
        }

        $payload = [
            'assistant_message' => trim($assistantMessage),
            'reports' => $reports,
            'plan' => $plan,
            'read_only' => true,
            'provider_ready' => $providerReady,
            'model' => $providerReady ? $model : null,
        ];

        // Backward compatibility with the first CareVMA AI UI.
        $first = $reports[0] ?? ['summary' => 'No records matched.', 'rows' => []];
        $payload['intent'] = $first['dataset'] ?? 'report';
        $payload['answer'] = [
            'summary' => $first['summary'] ?? 'No records matched.',
            'rows' => $first['rows'] ?? [],
        ];

        DB::table('ai_prompt_logs')->insert([
            'user_id' => $request->user()->id,
            'prompt' => $prompt,
            'intent' => collect($reports)->pluck('dataset')->filter()->join(','),
            'response' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($payload);
    }

    public function history(Request $request)
    {
        $this->authorizeAi($request);

        $rows = DB::table('ai_prompt_logs')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(30)
            ->get(['id', 'prompt', 'intent', 'response', 'created_at'])
            ->map(function ($row) {
                $decoded = json_decode((string) $row->response, true);
                return [
                    'id' => $row->id,
                    'prompt' => $row->prompt,
                    'intent' => $row->intent,
                    'assistant_message' => is_array($decoded) ? ($decoded['assistant_message'] ?? null) : null,
                    'created_at' => $row->created_at,
                ];
            });

        return response()->json(['data' => $rows]);
    }

    private function authorizeAi(Request $request): void
    {
        abort_unless(
            in_array((string) $request->user()?->role, ['admin', 'superadmin', 'hr'], true),
            403
        );
    }

    private function conversationContext(int $userId): array
    {
        $turns = (int) config('carevma_ai.context_turns', 6);
        if ($turns <= 0 || !Schema::hasTable('ai_prompt_logs')) {
            return [];
        }

        return DB::table('ai_prompt_logs')
            ->where('user_id', $userId)
            ->latest()
            ->limit($turns)
            ->get(['prompt', 'response'])
            ->reverse()
            ->values()
            ->map(function ($row) {
                $decoded = json_decode((string) $row->response, true);
                return [
                    'user' => (string) $row->prompt,
                    'assistant' => is_array($decoded)
                        ? Str::limit((string) ($decoded['assistant_message'] ?? ''), 800)
                        : '',
                ];
            })
            ->all();
    }

    private function planWithOpenAi(string $prompt, array $history): array
    {
        $maxRows = $this->maxRows();
        $catalog = $this->schemaCatalog();
        $today = now(config('carevma.business_timezone', 'Asia/Karachi'))->toDateString();

        $instructions = <<<TXT
You are the query planner for CareVMA, an attendance, scheduling, HR, probation, invoicing and workforce system.
Understand natural English, Roman Urdu, mixed English/Roman Urdu, informal spellings and typos like a helpful ChatGPT assistant.
Today is {$today}. CareVMA business timezone is Asia/Karachi unless the request explicitly refers to a Doctor timezone.

Return ONLY valid JSON. Do not return markdown or explanation.
Shape:
{
  "queries": [
    {
      "dataset": "employees|doctors|attendance|schedules|invoices|salaries|probation|overtime|live_workforce|employee_full_record|system_summary|records",
      "employee": null,
      "employee_code": null,
      "doctor": null,
      "month": null,
      "date_from": null,
      "date_to": null,
      "status": null,
      "late_only": false,
      "overtime_only": false,
      "active_only": false,
      "limit": 100,
      "sort": "latest",
      "table": null,
      "search": null,
      "filters": [],
      "sort_by": null,
      "sort_dir": "desc"
    }
  ]
}

Rules:
- Preserve person names as typed. Resolve pronouns/references from the prior conversation when possible.
- "sara/saray/sab/all/complete records" means request up to {$maxRows} rows.
- "Harram ka sara data", "full record", "complete profile/history" => employee_full_record.
- hazri/attendance/check in/check out/late/der se => attendance.
- tankhwa/salary/payroll/pay => salaries.
- bill/invoice/billing => invoices.
- shift/schedule/assigned doctor => schedules.
- probation/regularisation => probation.
- overtime/OT => overtime.
- abhi kaam kar raha/currently working/live workforce => live_workforce.
- employee/VA/staff/team member => employees. doctor/client => doctors.
- If the user asks for several independent things, return up to 4 queries.
- Use YYYY-MM for month and YYYY-MM-DD for dates. Resolve relative dates such as today, yesterday, this month and last month.
- For an unsupported/current/new CareVMA module, use dataset=records and choose a safe table from the schema catalog below.
- records.filters is an array of objects: {"column":"status","op":"=|!=|>|>=|<|<=|like|in|is_null|not_null","value":"..."}.
- Never request password/token/secret/authentication fields.
- Do not generate SQL.
- If intent is broad dashboard/overall counts, use system_summary.

Available safe database schema catalog:
{$catalog}
TXT;

        $contextText = collect($history)->map(function ($turn, $i) {
            $n = $i + 1;
            return "Turn {$n} user: {$turn['user']}\nTurn {$n} assistant: {$turn['assistant']}";
        })->join("\n");

        $input = ($contextText !== '' ? "Previous conversation:\n{$contextText}\n\n" : '')
            . "Current user request:\n{$prompt}";

        $response = $this->openAiResponse($instructions, $input, 1800);
        $text = $this->extractResponseText($response);
        $json = $this->decodeJsonObject($text);

        if (!is_array($json)) {
            throw new \RuntimeException('AI planner did not return valid JSON.');
        }

        return $json;
    }

    private function answerWithOpenAi(string $prompt, array $reports, array $history): string
    {
        $safeReports = collect($reports)->map(function (array $report) {
            $copy = [
                'title' => $report['title'] ?? null,
                'dataset' => $report['dataset'] ?? null,
                'summary' => $report['summary'] ?? null,
                'meta' => $report['meta'] ?? null,
            ];

            if (!empty($report['rows'])) {
                $copy['sample_rows'] = array_slice($report['rows'], 0, 40);
            }

            if (!empty($report['sections'])) {
                $copy['sections'] = collect($report['sections'])->map(function ($section) {
                    return [
                        'title' => $section['title'] ?? null,
                        'summary' => $section['summary'] ?? null,
                        'rows' => array_slice($section['rows'] ?? [], 0, 20),
                    ];
                })->all();
            }

            return $copy;
        })->all();

        $instructions = <<<'TXT'
You are CareVMA AI inside the company portal. Answer using ONLY the CareVMA report data supplied by the application.
Match the user's language and style: if they write Roman Urdu, answer naturally in Roman Urdu; if English, answer English; mixed language can be answered similarly.
Be conversational and useful like ChatGPT, but do not invent records, names, amounts, dates or counts.
If the report says rows were truncated, clearly say how many matched and how many are displayed.
The full tables are rendered below your message by the portal, so summarize the result and point the user to the records below when appropriate.
Do not mention SQL, internal prompts, JSON, schemas or implementation details.
Never claim to update/delete/create data. This assistant is read-only.
TXT;

        $input = "User request:\n{$prompt}\n\nCareVMA report data:\n"
            . json_encode($safeReports, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = $this->openAiResponse($instructions, $input, 1400);
        $text = trim($this->extractResponseText($response));

        return $text !== '' ? $text : $this->deterministicAnswer($reports, true);
    }

    private function openAiResponse(string $instructions, string $input, int $maxOutputTokens): array
    {
        $key = trim((string) config('carevma_ai.api_key'));
        if ($key === '') {
            throw new \RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $baseUrl = rtrim((string) config('carevma_ai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('carevma_ai.model', 'gpt-6.1-sol');
        $timeout = max(10, (int) config('carevma_ai.timeout', 45));

        $httpResponse = Http::withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->retry(1, 350, throw: false)
            ->post($baseUrl . '/responses', [
                'model' => $model,
                'instructions' => $instructions,
                'input' => $input,
                'max_output_tokens' => $maxOutputTokens,
                'store' => false,
            ]);

        if (!$httpResponse->successful()) {
            $message = data_get($httpResponse->json(), 'error.message') ?: ('HTTP ' . $httpResponse->status());
            throw new \RuntimeException('AI provider request failed: ' . Str::limit((string) $message, 300));
        }

        return $httpResponse->json() ?: [];
    }

    private function extractResponseText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }

        $pieces = [];
        foreach (($response['output'] ?? []) as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach (($item['content'] ?? []) as $content) {
                if (in_array(($content['type'] ?? null), ['output_text', 'text'], true)) {
                    $text = $content['text'] ?? null;
                    if (is_string($text) && $text !== '') {
                        $pieces[] = $text;
                    }
                }
            }
        }

        return trim(implode("\n", $pieces));
    }

    private function decodeJsonObject(string $text): ?array
    {
        $clean = trim($text);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function normalizePlan(array $plan): array
    {
        $queries = $plan['queries'] ?? [];
        if (!is_array($queries) || $queries === []) {
            $queries = [['dataset' => 'system_summary']];
        }

        $normalized = [];
        foreach (array_slice($queries, 0, 4) as $query) {
            if (!is_array($query)) {
                continue;
            }

            $dataset = strtolower(trim((string) ($query['dataset'] ?? 'system_summary')));
            if (!in_array($dataset, self::DATASETS, true)) {
                $dataset = 'records';
            }

            $limit = (int) ($query['limit'] ?? 100);
            if ($limit <= 0) {
                $limit = 100;
            }

            $normalized[] = [
                'dataset' => $dataset,
                'employee' => $this->nullableString($query['employee'] ?? null),
                'employee_code' => $this->nullableString($query['employee_code'] ?? null),
                'doctor' => $this->nullableString($query['doctor'] ?? null),
                'month' => $this->normalizeMonth($query['month'] ?? null),
                'date_from' => $this->normalizeDate($query['date_from'] ?? null),
                'date_to' => $this->normalizeDate($query['date_to'] ?? null),
                'status' => $this->nullableString($query['status'] ?? null),
                'late_only' => (bool) ($query['late_only'] ?? false),
                'overtime_only' => (bool) ($query['overtime_only'] ?? false),
                'active_only' => (bool) ($query['active_only'] ?? false),
                'limit' => min($this->maxRows(), $limit),
                'sort' => in_array(($query['sort'] ?? 'latest'), ['latest', 'oldest', 'name'], true)
                    ? $query['sort']
                    : 'latest',
                'table' => $this->nullableString($query['table'] ?? null),
                'search' => $this->nullableString($query['search'] ?? null),
                'filters' => is_array($query['filters'] ?? null) ? array_slice($query['filters'], 0, 12) : [],
                'sort_by' => $this->nullableString($query['sort_by'] ?? null),
                'sort_dir' => strtolower((string) ($query['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
            ];
        }

        return ['queries' => $normalized ?: [['dataset' => 'system_summary', 'limit' => 100]]];
    }

    private function fallbackPlan(string $prompt): array
    {
        $p = mb_strtolower($prompt);
        $dataset = 'employees';

        if ($this->containsAny($p, ['sara data', 'sara record', 'saray record', 'full record', 'complete record', 'complete profile'])) {
            $dataset = 'employee_full_record';
        } elseif ($this->containsAny($p, ['attendance', 'hazri', 'late', 'check in', 'check-in', 'clock in', 'clock-in'])) {
            $dataset = 'attendance';
        } elseif ($this->containsAny($p, ['invoice', 'billing', 'bill '])) {
            $dataset = 'invoices';
        } elseif ($this->containsAny($p, ['salary', 'tankha', 'tankhwa', 'payroll'])) {
            $dataset = 'salaries';
        } elseif ($this->containsAny($p, ['probation', 'regularisation', 'regularization'])) {
            $dataset = 'probation';
        } elseif ($this->containsAny($p, ['overtime', ' ot '])) {
            $dataset = 'overtime';
        } elseif ($this->containsAny($p, ['schedule', 'shift', 'assigned doctor'])) {
            $dataset = 'schedules';
        } elseif ($this->containsAny($p, ['currently working', 'working now', 'live workforce', 'abhi kaam'])) {
            $dataset = 'live_workforce';
        } elseif ($this->containsAny($p, ['doctor', 'client'])) {
            $dataset = 'doctors';
        } elseif ($this->containsAny($p, ['summary', 'overview', 'dashboard'])) {
            $dataset = 'system_summary';
        }

        $month = null;
        if (preg_match('/\b(20\d{2})[-\/ ](0?[1-9]|1[0-2])\b/', $p, $m)) {
            $month = sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
        }

        return [
            'queries' => [[
                'dataset' => $dataset,
                'month' => $month,
                'late_only' => str_contains($p, 'late'),
                'overtime_only' => str_contains($p, 'overtime'),
                'limit' => $this->containsAny($p, ['all', 'sab', 'sara', 'saray']) ? $this->maxRows() : 100,
            ]],
        ];
    }

    private function executeQuery(array $query): array
    {
        return match ($query['dataset']) {
            'employees' => $this->reportEmployees($query),
            'doctors' => $this->reportDoctors($query),
            'attendance' => $this->reportAttendance($query),
            'schedules' => $this->reportSchedules($query),
            'invoices' => $this->reportInvoices($query),
            'salaries' => $this->reportSalaries($query),
            'probation' => $this->reportProbation($query),
            'overtime' => $this->reportOvertime($query),
            'live_workforce' => $this->reportLiveWorkforce($query),
            'employee_full_record' => $this->reportEmployeeFullRecord($query),
            'records' => $this->reportGenericRecords($query),
            default => $this->reportSystemSummary($query),
        };
    }

    private function reportEmployees(array $q): array
    {
        $query = Employee::query()->with([
            'user', 'department', 'designation', 'employmentType', 'employeeStatus', 'reportingManager',
        ]);
        $this->filterEmployeeBuilder($query, $q['employee'], $q['employee_code']);

        if ($q['active_only'] && Schema::hasColumn('users', 'is_active')) {
            $query->whereHas('user', fn ($u) => $u->where('is_active', true));
        }

        if ($q['status']) {
            $status = $q['status'];
            $query->whereHas('employeeStatus', fn ($s) => $s->where('name', 'like', "%{$status}%"));
        }

        $total = (clone $query)->count();
        $this->sortEmployeeQuery($query, $q['sort']);
        $rows = $query->limit($q['limit'])->get()->map(fn (Employee $e) => [
            'employee_id' => $e->id,
            'employee' => $this->employeeName($e),
            'code' => $e->employee_code ?? null,
            'email' => $e->user?->email ?? $e->email,
            'role' => $e->user?->role,
            'department' => $e->department?->name,
            'designation' => $e->designation?->name,
            'employment_type' => $e->employmentType?->name,
            'status' => $this->employeeStatusLabel($e),
            'timezone' => $e->timezone,
            'joining_date' => $this->dateString($e->date_of_joining),
            'probation_end' => $this->dateString($e->probation_end_date),
        ])->values()->all();

        return $this->report('employees', 'Employees / VAs', $total, $rows, $q['limit']);
    }

    private function reportDoctors(array $q): array
    {
        $query = Client::query()->with(['user', 'service', 'contractType']);
        $this->filterDoctorBuilder($query, $q['doctor']);

        if ($q['active_only'] && Schema::hasColumn('users', 'is_active')) {
            $query->whereHas('user', fn ($u) => $u->where('is_active', true));
        }

        $total = (clone $query)->count();
        if ($q['sort'] === 'name') {
            $query->orderBy('name');
        } else {
            $query->orderByDesc('id');
        }

        $rows = $query->limit($q['limit'])->get()->map(fn (Client $c) => [
            'doctor_id' => $c->id,
            'doctor' => $this->doctorName($c),
            'email' => $c->user?->email ?? $c->email,
            'business' => $c->business_name,
            'timezone' => $c->timezone,
            'service' => $c->service?->name,
            'contract_type' => $c->contractType?->name,
            'hourly_charge' => $c->per_hour_charges,
            'status' => $this->userActiveLabel($c->user),
        ])->values()->all();

        return $this->report('doctors', 'Doctors / Clients', $total, $rows, $q['limit']);
    }

    private function reportAttendance(array $q): array
    {
        $query = Attendance::query()->with(['employee.user', 'client.user']);
        $this->filterAttendancePeople($query, $q);
        $this->applyDateRange($query, 'date', $q);

        if ($q['late_only']) {
            if (Schema::hasColumn('attendances', 'late_minutes')) {
                $query->where('late_minutes', '>', 0);
            } elseif (Schema::hasColumn('attendances', 'is_late')) {
                $query->where('is_late', true);
            }
        }

        if ($q['overtime_only']) {
            $this->applyOvertimeWhere($query);
        }

        $total = (clone $query)->count();
        $q['sort'] === 'oldest' ? $query->orderBy('date')->orderBy('id') : $query->orderByDesc('date')->orderByDesc('id');

        $rows = $query->limit($q['limit'])->get()->map(function (Attendance $a) {
            $timezone = $a->client?->timezone ?: config('carevma.business_timezone', 'Asia/Karachi');
            return [
                'attendance_id' => $a->id,
                'date' => $this->dateString($a->date),
                'employee' => $this->employeeName($a->employee),
                'code' => $a->employee?->employee_code,
                'doctor' => $this->doctorName($a->client),
                'doctor_timezone' => $timezone,
                'clock_in' => $this->dateTimeString($a->clock_in, $timezone),
                'clock_out' => $this->dateTimeString($a->clock_out, $timezone),
                'break_taken' => $a->break_taken,
                'total_minutes' => $a->total_minutes ?? null,
                'late_minutes' => $a->late_minutes ?? 0,
                'approved_overtime_minutes' => $a->approved_overtime_minutes ?? null,
                'overtime_status' => $a->overtime_status ?? null,
            ];
        })->values()->all();

        return $this->report('attendance', 'Attendance', $total, $rows, $q['limit']);
    }

    private function reportSchedules(array $q): array
    {
        $query = EmployeeClientSchedule::query()->with(['employee.user', 'client.user']);
        if ($q['employee'] || $q['employee_code']) {
            $name = $q['employee'];
            $code = $q['employee_code'];
            $query->whereHas('employee', fn ($e) => $this->filterEmployeeBuilder($e, $name, $code));
        }
        if ($q['doctor']) {
            $doctor = $q['doctor'];
            $query->whereHas('client', fn ($c) => $this->filterDoctorBuilder($c, $doctor));
        }
        if ($q['active_only'] && Schema::hasColumn('employee_client_schedules', 'enabled')) {
            $query->where('enabled', true);
        }
        if (Schema::hasColumn('employee_client_schedules', 'schedule_date')) {
            $this->applyDateRange($query, 'schedule_date', $q);
        }

        $total = (clone $query)->count();
        $query->orderByDesc(Schema::hasColumn('employee_client_schedules', 'schedule_date') ? 'schedule_date' : 'id')
            ->orderByDesc('id');

        $rows = $query->limit($q['limit'])->get()->map(fn (EmployeeClientSchedule $s) => [
            'schedule_id' => $s->id,
            'date' => $this->dateString($s->schedule_date ?? null),
            'weekday' => $s->weekday,
            'employee' => $this->employeeName($s->employee),
            'doctor' => $this->doctorName($s->client),
            'start' => $s->start_time,
            'end' => $s->end_time,
            'timezone' => $s->timezone ?: $s->client?->timezone,
            'break_allowed' => $s->break_allowed_hours ?? null,
            'enabled' => isset($s->enabled) ? (bool) $s->enabled : null,
            'repeat' => isset($s->repeat) ? (bool) $s->repeat : null,
        ])->values()->all();

        return $this->report('schedules', 'Schedules / Assignments', $total, $rows, $q['limit']);
    }

    private function reportInvoices(array $q): array
    {
        $query = Invoice::query()->with(['client.user']);
        if ($q['doctor']) {
            $doctor = $q['doctor'];
            $query->whereHas('client', fn ($c) => $this->filterDoctorBuilder($c, $doctor));
        }
        if ($q['status'] && Schema::hasColumn('invoices', 'status')) {
            $query->where('status', 'like', '%' . $q['status'] . '%');
        }

        $dateColumn = Schema::hasColumn('invoices', 'billing_cycle_month')
            ? 'billing_cycle_month'
            : (Schema::hasColumn('invoices', 'period_end') ? 'period_end' : 'created_at');
        $this->applyDateRange($query, $dateColumn, $q);

        $total = (clone $query)->count();
        $q['sort'] === 'oldest' ? $query->orderBy('id') : $query->orderByDesc('id');

        $rows = $query->limit($q['limit'])->get()->map(fn (Invoice $i) => [
            'invoice_id' => $i->id,
            'invoice' => $i->invoice_number,
            'doctor' => $this->doctorName($i->client),
            'period_start' => $this->dateString($i->period_start),
            'period_end' => $this->dateString($i->period_end),
            'billing_month' => $this->dateString($i->billing_cycle_month ?? null, 'Y-m'),
            'hours' => $i->total_hours,
            'amount' => $i->total_amount,
            'status' => $i->status ?? null,
            'auto_generated' => isset($i->auto_generated) ? (bool) $i->auto_generated : null,
            'generated_at' => $this->dateTimeString($i->generated_at),
        ])->values()->all();

        $report = $this->report('invoices', 'Invoices', $total, $rows, $q['limit']);
        $report['meta']['returned_amount_total'] = round((float) collect($rows)->sum(fn ($r) => (float) ($r['amount'] ?? 0)), 2);
        return $report;
    }

    private function reportSalaries(array $q): array
    {
        $query = EmployeeSalary::query()->with(['employee.user', 'client.user', 'invoice']);
        if ($q['employee'] || $q['employee_code']) {
            $name = $q['employee'];
            $code = $q['employee_code'];
            $query->whereHas('employee', fn ($e) => $this->filterEmployeeBuilder($e, $name, $code));
        }
        if ($q['doctor']) {
            $doctor = $q['doctor'];
            $query->whereHas('client', fn ($c) => $this->filterDoctorBuilder($c, $doctor));
        }
        $this->applyDateRange($query, 'period_end', $q);

        $total = (clone $query)->count();
        $q['sort'] === 'oldest' ? $query->orderBy('period_end') : $query->orderByDesc('period_end');

        $rows = $query->limit($q['limit'])->get()->map(fn (EmployeeSalary $s) => [
            'salary_id' => $s->id,
            'employee' => $this->employeeName($s->employee),
            'doctor' => $this->doctorName($s->client),
            'invoice' => $s->invoice?->invoice_number,
            'period_start' => $this->dateString($s->period_start),
            'period_end' => $this->dateString($s->period_end),
            'hours' => $s->total_hours,
            'salary_amount' => $s->salary_amount,
            'late' => $s->total_late ?? null,
            'overtime' => $s->total_overtime ?? null,
        ])->values()->all();

        $report = $this->report('salaries', 'Salary / Payroll Records', $total, $rows, $q['limit']);
        $report['meta']['returned_salary_total'] = round((float) collect($rows)->sum(fn ($r) => (float) ($r['salary_amount'] ?? 0)), 2);
        return $report;
    }

    private function reportProbation(array $q): array
    {
        $query = Employee::query()->with(['user', 'employmentType']);
        $this->filterEmployeeBuilder($query, $q['employee'], $q['employee_code']);
        $employees = $query->orderBy('id')->get();

        $rows = $employees->map(function (Employee $e) {
            $status = method_exists($e, 'probationStatusData')
                ? $e->probationStatusData()
                : $this->fallbackProbationData($e);

            return [
                'employee_id' => $e->id,
                'employee' => $this->employeeName($e),
                'code' => $e->employee_code ?? null,
                'status' => $status['label'] ?? $status['status'] ?? null,
                'start_date' => $this->dateString($e->probation_start_date ?? $e->date_of_joining),
                'end_date' => $status['end_date'] ?? $this->dateString($e->probation_end_date),
                'regularisation_date' => $status['regularisation_date'] ?? $this->dateString($e->date_of_regularisation),
                'days_remaining' => $status['days_remaining'] ?? null,
            ];
        });

        if ($q['status']) {
            $needle = mb_strtolower($q['status']);
            $rows = $rows->filter(fn ($row) => str_contains(mb_strtolower((string) ($row['status'] ?? '')), $needle));
        } else {
            $rows = $rows->filter(fn ($row) => !str_contains(mb_strtolower((string) ($row['status'] ?? '')), 'permanent'));
        }

        $total = $rows->count();
        $rows = $rows->take($q['limit'])->values()->all();

        return $this->report('probation', 'Probation', $total, $rows, $q['limit']);
    }

    private function reportOvertime(array $q): array
    {
        $q['overtime_only'] = true;
        $report = $this->reportAttendance($q);
        $report['dataset'] = 'overtime';
        $report['title'] = 'Overtime';
        $report['summary'] = str_replace('Attendance', 'Overtime', $report['summary']);
        return $report;
    }

    private function reportLiveWorkforce(array $q): array
    {
        $query = Attendance::query()->with(['employee.user', 'client.user'])->whereNull('clock_out');
        $this->filterAttendancePeople($query, $q);
        $total = (clone $query)->count();

        $rows = $query->orderByDesc('clock_in')->limit($q['limit'])->get()->map(function (Attendance $a) {
            $timezone = $a->client?->timezone ?: config('carevma.business_timezone', 'Asia/Karachi');
            return [
                'employee' => $this->employeeName($a->employee),
                'code' => $a->employee?->employee_code,
                'doctor' => $this->doctorName($a->client),
                'doctor_timezone' => $timezone,
                'clock_in' => $this->dateTimeString($a->clock_in, $timezone),
                'on_break' => method_exists($a, 'relationLoaded') && $a->relationLoaded('openBreak') ? (bool) $a->openBreak : null,
                'late_minutes' => $a->late_minutes ?? 0,
            ];
        })->values()->all();

        return $this->report('live_workforce', 'Live Workforce', $total, $rows, $q['limit']);
    }

    private function reportEmployeeFullRecord(array $q): array
    {
        $employeeQuery = Employee::query()->with([
            'user', 'department', 'designation', 'employmentType', 'employeeStatus', 'reportingManager',
        ]);
        $this->filterEmployeeBuilder($employeeQuery, $q['employee'], $q['employee_code']);

        $matches = (clone $employeeQuery)->limit(10)->get();
        if ($matches->isEmpty()) {
            return [
                'dataset' => 'employee_full_record',
                'title' => 'Employee Full Record',
                'summary' => 'No matching employee/VA was found.',
                'rows' => [],
                'sections' => [],
                'meta' => ['total_matches' => 0, 'returned' => 0, 'truncated' => false],
            ];
        }

        // If the AI/user supplied an ambiguous name, do not silently combine people.
        if ($matches->count() > 1 && !$q['employee_code']) {
            $rows = $matches->map(fn (Employee $e) => [
                'employee' => $this->employeeName($e),
                'code' => $e->employee_code ?? null,
                'email' => $e->user?->email ?? $e->email,
            ])->all();
            return [
                'dataset' => 'employee_full_record',
                'title' => 'Employee Full Record',
                'summary' => 'More than one employee matched. Use the employee code or a more specific name.',
                'rows' => $rows,
                'sections' => [],
                'meta' => ['total_matches' => $matches->count(), 'returned' => $matches->count(), 'truncated' => false],
            ];
        }

        /** @var Employee $e */
        $e = $matches->first();
        $name = $this->employeeName($e);
        $sections = [];

        $sections[] = [
            'title' => 'Profile',
            'rows' => [[
                'employee_id' => $e->id,
                'employee' => $name,
                'code' => $e->employee_code ?? null,
                'email' => $e->user?->email ?? $e->email,
                'phone' => $e->phone ?? null,
                'department' => $e->department?->name,
                'designation' => $e->designation?->name,
                'employment_type' => $e->employmentType?->name,
                'status' => $this->employeeStatusLabel($e),
                'timezone' => $e->timezone,
                'joining_date' => $this->dateString($e->date_of_joining),
                'regularisation_date' => $this->dateString($e->date_of_regularisation),
            ]],
        ];

        $scheduleQ = $q;
        $scheduleQ['employee'] = null;
        $scheduleQ['employee_code'] = $e->employee_code ?? null;
        $scheduleReport = $this->reportSchedules($scheduleQ);
        $sections[] = ['title' => 'Schedules / Assigned Doctors', 'rows' => $scheduleReport['rows'], 'summary' => $scheduleReport['summary']];

        $attendanceQ = $q;
        $attendanceQ['employee'] = null;
        $attendanceQ['employee_code'] = $e->employee_code ?? null;
        $attendanceReport = $this->reportAttendance($attendanceQ);
        $sections[] = ['title' => 'Attendance', 'rows' => $attendanceReport['rows'], 'summary' => $attendanceReport['summary']];

        $salaryQ = $q;
        $salaryQ['employee'] = null;
        $salaryQ['employee_code'] = $e->employee_code ?? null;
        $salaryReport = $this->reportSalaries($salaryQ);
        $sections[] = ['title' => 'Salary / Payroll', 'rows' => $salaryReport['rows'], 'summary' => $salaryReport['summary']];

        $probationQ = $q;
        $probationQ['employee'] = null;
        $probationQ['employee_code'] = $e->employee_code ?? null;
        $probationReport = $this->reportProbation($probationQ);
        $sections[] = ['title' => 'Probation', 'rows' => $probationReport['rows'], 'summary' => $probationReport['summary']];

        return [
            'dataset' => 'employee_full_record',
            'title' => "Full Record — {$name}",
            'summary' => "Complete authorized CareVMA record assembled for {$name}.",
            'rows' => [],
            'sections' => $sections,
            'meta' => ['total_matches' => 1, 'returned' => 1, 'truncated' => false],
        ];
    }

    private function reportSystemSummary(array $q): array
    {
        $today = now(config('carevma.business_timezone', 'Asia/Karachi'))->toDateString();
        $monthStart = now(config('carevma.business_timezone', 'Asia/Karachi'))->startOfMonth()->toDateString();
        $monthEnd = now(config('carevma.business_timezone', 'Asia/Karachi'))->endOfMonth()->toDateString();

        $rows = [[
            'employees' => Schema::hasTable('employees') ? DB::table('employees')->count() : 0,
            'doctors' => Schema::hasTable('clients') ? DB::table('clients')->count() : 0,
            'attendance_today' => Schema::hasTable('attendances') ? DB::table('attendances')->whereDate('date', $today)->count() : 0,
            'currently_open_attendance' => Schema::hasTable('attendances') ? DB::table('attendances')->whereNull('clock_out')->count() : 0,
            'invoices_this_month' => Schema::hasTable('invoices') ? DB::table('invoices')->whereBetween('period_end', [$monthStart, $monthEnd])->count() : 0,
            'current_date' => $today,
        ]];

        return [
            'dataset' => 'system_summary',
            'title' => 'CareVMA Overview',
            'summary' => 'Current CareVMA operational overview.',
            'rows' => $rows,
            'meta' => ['total_matches' => 1, 'returned' => 1, 'truncated' => false],
        ];
    }

    private function reportGenericRecords(array $q): array
    {
        $table = strtolower(trim((string) ($q['table'] ?? '')));
        if ($table === '' || !in_array($table, self::SAFE_TABLES, true) || !Schema::hasTable($table)) {
            return [
                'dataset' => 'records',
                'title' => 'CareVMA Records',
                'summary' => 'The requested module/table is not available in the safe read-only catalog.',
                'rows' => [],
                'meta' => ['total_matches' => 0, 'returned' => 0, 'truncated' => false],
            ];
        }

        $safeColumns = $this->safeColumns($table);
        if ($safeColumns === []) {
            return [
                'dataset' => 'records',
                'title' => Str::headline($table),
                'summary' => 'No safe readable columns are available for this module.',
                'rows' => [],
                'meta' => ['total_matches' => 0, 'returned' => 0, 'truncated' => false],
            ];
        }

        $builder = DB::table($table)->select($safeColumns);
        foreach ($q['filters'] as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $column = (string) ($filter['column'] ?? '');
            $op = strtolower((string) ($filter['op'] ?? '='));
            $value = $filter['value'] ?? null;
            if (!in_array($column, $safeColumns, true)) {
                continue;
            }

            switch ($op) {
                case '=':
                case '!=':
                case '>':
                case '>=':
                case '<':
                case '<=':
                    $builder->where($column, $op, $value);
                    break;
                case 'like':
                    $builder->where($column, 'like', '%' . (string) $value . '%');
                    break;
                case 'in':
                    $values = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string) $value)));
                    if ($values !== []) {
                        $builder->whereIn($column, array_slice($values, 0, 100));
                    }
                    break;
                case 'is_null':
                    $builder->whereNull($column);
                    break;
                case 'not_null':
                    $builder->whereNotNull($column);
                    break;
            }
        }

        if ($q['search']) {
            $search = $q['search'];
            $searchable = array_values(array_filter($safeColumns, fn ($column) => !in_array($column, ['id', 'created_at', 'updated_at'], true)));
            $builder->where(function ($w) use ($searchable, $search) {
                foreach (array_slice($searchable, 0, 12) as $i => $column) {
                    $i === 0
                        ? $w->where($column, 'like', "%{$search}%")
                        : $w->orWhere($column, 'like', "%{$search}%");
                }
            });
        }

        $total = (clone $builder)->count();
        $sortBy = in_array($q['sort_by'], $safeColumns, true)
            ? $q['sort_by']
            : (in_array('created_at', $safeColumns, true) ? 'created_at' : (in_array('id', $safeColumns, true) ? 'id' : $safeColumns[0]));
        $builder->orderBy($sortBy, $q['sort_dir']);

        $rows = $builder->limit($q['limit'])->get()->map(fn ($row) => (array) $row)->all();
        $rows = $this->enrichGenericRows($rows);

        return $this->report('records', Str::headline($table), $total, $rows, $q['limit'], ['table' => $table]);
    }

    private function report(string $dataset, string $title, int $total, array $rows, int $limit, array $extraMeta = []): array
    {
        $returned = count($rows);
        return [
            'dataset' => $dataset,
            'title' => $title,
            'summary' => "{$total} record(s) matched; {$returned} displayed.",
            'rows' => $rows,
            'meta' => array_merge([
                'total_matches' => $total,
                'returned' => $returned,
                'truncated' => $total > $returned,
                'max_rows' => $limit,
            ], $extraMeta),
        ];
    }

    private function filterEmployeeBuilder(Builder $query, ?string $name, ?string $code): void
    {
        if ($code) {
            if (Schema::hasColumn('employees', 'employee_code')) {
                $query->where('employee_code', 'like', '%' . $code . '%');
            }
        }

        if (!$name) {
            return;
        }

        $query->where(function (Builder $w) use ($name) {
            $like = '%' . $name . '%';
            if (Schema::hasColumn('employees', 'first_name')) {
                $w->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhereRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) LIKE ?", [$like]);
            }
            $w->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like));
        });
    }

    private function filterDoctorBuilder(Builder $query, ?string $name): void
    {
        if (!$name) {
            return;
        }
        $like = '%' . $name . '%';
        $query->where(function (Builder $w) use ($like) {
            if (Schema::hasColumn('clients', 'name')) {
                $w->where('name', 'like', $like);
            }
            if (Schema::hasColumn('clients', 'business_name')) {
                $w->orWhere('business_name', 'like', $like);
            }
            $w->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like));
        });
    }

    private function filterAttendancePeople(Builder $query, array $q): void
    {
        if ($q['employee'] || $q['employee_code']) {
            $name = $q['employee'];
            $code = $q['employee_code'];
            $query->whereHas('employee', fn ($e) => $this->filterEmployeeBuilder($e, $name, $code));
        }
        if ($q['doctor']) {
            $doctor = $q['doctor'];
            $query->whereHas('client', fn ($c) => $this->filterDoctorBuilder($c, $doctor));
        }
    }

    private function applyDateRange($query, string $column, array $q): void
    {
        [$from, $to] = $this->dateRange($q);
        if ($from) {
            $query->whereDate($column, '>=', $from);
        }
        if ($to) {
            $query->whereDate($column, '<=', $to);
        }
    }

    private function dateRange(array $q): array
    {
        $from = $q['date_from'] ?? null;
        $to = $q['date_to'] ?? null;

        if (!$from && !$to && !empty($q['month']) && preg_match('/^\d{4}-\d{2}$/', $q['month'])) {
            $month = Carbon::createFromFormat('Y-m-d', $q['month'] . '-01', config('carevma.business_timezone', 'Asia/Karachi'));
            $from = $month->copy()->startOfMonth()->toDateString();
            $to = $month->copy()->endOfMonth()->toDateString();
        }

        return [$from, $to];
    }

    private function applyOvertimeWhere(Builder $query): void
    {
        if (Schema::hasColumn('attendances', 'approved_overtime_minutes')) {
            $query->where('approved_overtime_minutes', '>', 0);
        } elseif (Schema::hasColumn('attendances', 'overtime')) {
            $query->where(function ($w) {
                $w->where('overtime', '>', 0)->orWhereNotNull('overtime');
            });
        }
    }

    private function sortEmployeeQuery(Builder $query, string $sort): void
    {
        if ($sort === 'name') {
            $query->orderBy('first_name')->orderBy('last_name');
        } elseif ($sort === 'oldest') {
            $query->orderBy('id');
        } else {
            $query->orderByDesc('id');
        }
    }

    private function employeeName(?Employee $employee): ?string
    {
        if (!$employee) {
            return null;
        }
        $userName = trim((string) $employee->user?->name);
        if ($userName !== '') {
            return $userName;
        }
        $name = trim((string) (($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')));
        return $name !== '' ? $name : null;
    }

    private function doctorName(?Client $client): ?string
    {
        if (!$client) {
            return null;
        }
        return trim((string) ($client->user?->name ?: $client->name ?: $client->business_name)) ?: null;
    }

    private function employeeStatusLabel(Employee $employee): ?string
    {
        if ($employee->user && array_key_exists('is_active', $employee->user->getAttributes())) {
            if (!$employee->user->is_active) {
                return 'Inactive';
            }
        }
        return $employee->employeeStatus?->name ?: 'Active';
    }

    private function userActiveLabel($user): ?string
    {
        if (!$user) {
            return null;
        }
        if (array_key_exists('is_active', $user->getAttributes())) {
            return $user->is_active ? 'Active' : 'Inactive';
        }
        return 'Active';
    }

    private function fallbackProbationData(Employee $employee): array
    {
        $end = $employee->probation_end_date ? Carbon::parse($employee->probation_end_date)->startOfDay() : null;
        if (!$end) {
            return ['status' => 'not_set', 'label' => 'Probation not set', 'end_date' => null, 'days_remaining' => null];
        }
        $today = Carbon::today(config('carevma.business_timezone', 'Asia/Karachi'));
        return [
            'status' => $end->lt($today) ? 'due' : 'probation',
            'label' => $end->lt($today) ? 'Probation due for regularisation' : 'On probation',
            'end_date' => $end->toDateString(),
            'days_remaining' => max(0, $today->diffInDays($end, false)),
        ];
    }

    private function schemaCatalog(): string
    {
        $catalog = [];
        foreach (self::SAFE_TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $columns = $this->safeColumns($table);
            if ($columns !== []) {
                $catalog[] = $table . '(' . implode(',', $columns) . ')';
            }
        }
        return implode("\n", $catalog);
    }

    private function safeColumns(string $table): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }
        return array_values(array_filter(Schema::getColumnListing($table), function ($column) {
            $lower = strtolower((string) $column);
            if (in_array($lower, self::DENIED_COLUMNS, true)) {
                return false;
            }
            foreach (['password', 'secret', 'token', 'api_key', 'remember_token'] as $blocked) {
                if (str_contains($lower, $blocked)) {
                    return false;
                }
            }
            return true;
        }));
    }

    private function enrichGenericRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $employeeIds = collect($rows)->pluck('employee_id')->filter()->unique()->values();
        $clientIds = collect($rows)->pluck('client_id')->filter()->unique()->values();
        $userIds = collect($rows)->pluck('user_id')->filter()->unique()->values();

        $employeeNames = $employeeIds->isEmpty() || !Schema::hasTable('employees')
            ? collect()
            : Employee::query()->with('user')->whereIn('id', $employeeIds)->get()->mapWithKeys(fn ($e) => [$e->id => $this->employeeName($e)]);
        $doctorNames = $clientIds->isEmpty() || !Schema::hasTable('clients')
            ? collect()
            : Client::query()->with('user')->whereIn('id', $clientIds)->get()->mapWithKeys(fn ($c) => [$c->id => $this->doctorName($c)]);
        $userNames = $userIds->isEmpty() || !Schema::hasTable('users')
            ? collect()
            : DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id');

        return array_map(function ($row) use ($employeeNames, $doctorNames, $userNames) {
            if (isset($row['employee_id']) && $employeeNames->has($row['employee_id'])) {
                $row['employee_name'] = $employeeNames->get($row['employee_id']);
            }
            if (isset($row['client_id']) && $doctorNames->has($row['client_id'])) {
                $row['doctor_name'] = $doctorNames->get($row['client_id']);
            }
            if (isset($row['user_id']) && $userNames->has($row['user_id'])) {
                $row['user_name'] = $userNames->get($row['user_id']);
            }
            return $row;
        }, $rows);
    }

    private function deterministicAnswer(array $reports, bool $providerHadIssue): string
    {
        if ($reports === []) {
            return 'No matching CareVMA records were found.';
        }

        $parts = [];
        foreach ($reports as $report) {
            $parts[] = ($report['title'] ?? 'CareVMA') . ': ' . ($report['summary'] ?? 'Report ready.');
        }
        if ($providerHadIssue) {
            $parts[] = 'The CareVMA records below are still from the live database; only the conversational wording fallback was used.';
        }
        return implode("\n", $parts);
    }

    private function maxRows(): int
    {
        return max(50, min(5000, (int) config('carevma_ai.max_rows', 1000)));
    }

    private function normalizeMonth(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' || strtolower($value) === 'null' ? null : Str::limit($value, 200, '');
    }

    private function dateString(mixed $value, string $format = 'Y-m-d'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return $value instanceof \DateTimeInterface
                ? Carbon::instance($value)->format($format)
                : Carbon::parse((string) $value)->format($format);
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function dateTimeString(mixed $value, ?string $timezone = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $carbon = $value instanceof \DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse((string) $value, 'UTC');
            if ($timezone) {
                $carbon = $carbon->copy()->setTimezone($timezone);
            }
            return $carbon->format('Y-m-d h:i A');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
