<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Client;
use App\Models\Employee;
use App\Services\ClientTimezoneService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DoctorApiController extends Controller
{
    public function __construct(private ClientTimezoneService $timezones)
    {
    }

    public function dashboard(Request $request)
    {
        $client = $this->client($request);

        // A VA may have several weekday schedules for the same client. Loading through the
        // belongsToMany relation includes pivot columns, so SQL DISTINCT can still return one
        // employee row per schedule. Resolve unique employee IDs first, then load each VA once.
        $employeeIds = $client->employeeSchedules()
            ->where('enabled', true)
            ->select('employee_id')
            ->distinct()
            ->pluck('employee_id');

        // Preserve visibility of legacy assignments whose enabled value may be null/legacy.
        if ($employeeIds->isEmpty()) {
            $employeeIds = $client->employeeSchedules()->select('employee_id')->distinct()->pluck('employee_id');
        }

        $employees = Employee::query()
            ->with(['department', 'designation', 'employeeStatus'])
            ->whereIn('id', $employeeIds)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
        return response()->json([
            'client' => $client,
            'client_local_now' => $this->timezones->now($client)->toIso8601String(),
            'employees' => $employees,
            'metrics' => [
                'assigned_employees' => $employees->count(),
                'attendance_sessions' => Schema::hasColumn('attendances', 'client_id') ? Attendance::where('client_id', $client->id)->count() : Attendance::whereIn('employee_id', $employees->pluck('id'))->count(),
            ],
        ]);
    }

    public function employeeAttendance(Request $request, Employee $employee)
    {
        $client = $this->client($request); $this->assertAssigned($client, $employee);
        $query = Attendance::with('breaks')->where('employee_id', $employee->id);
        if (Schema::hasColumn('attendances', 'client_id')) $query->where('client_id', $client->id);
        [$filterStartUtc, $filterEndUtc] = $this->timezones->utcRangeForLocalFilters(
            $request->input('from'),
            $request->input('to'),
            $client
        );
        if ($filterStartUtc) $query->where('clock_in', '>=', $filterStartUtc);
        if ($filterEndUtc) $query->where('clock_in', '<=', $filterEndUtc);
        if ($search = trim((string) $request->query('search'))) $query->where(fn ($q) => $q->where('date', 'like', "%{$search}%")->orWhere('clock_in', 'like', "%{$search}%")->orWhere('clock_out', 'like', "%{$search}%"));
        $attendance = $query->latest('date')->latest('id')->paginate(min(max((int) $request->query('per_page', 25), 1), 100));
        $attendance->through(function (Attendance $row) use ($client, $employee) {
            $zone = $this->timezones->forClient($client);
            $row->setAttribute('client_timezone', $zone);
            $row->setAttribute('employee_timezone', $employee->timezone ?: 'UTC');
            $row->setAttribute('clock_in_client', $this->timezones->isoInZone($row->clock_in, $zone));
            $row->setAttribute('clock_out_client', $this->timezones->isoInZone($row->clock_out, $zone));
            $row->setAttribute('client_date', $row->clock_in ? $this->timezones->localDate($row->clock_in, $zone) : optional($row->date)->toDateString());
            return $row;
        });

        return response()->json([
            'client' => $client->only(['id', 'name', 'business_name', 'timezone']),
            'employee' => $employee,
            'attendance' => $attendance,
        ]);
    }

    public function profile(Request $request)
    {
        return response()->json($this->client($request)->load(['service', 'contractType', 'user']));
    }

    public function updateProfile(Request $request)
    {
        $client = $this->client($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($request->user()->id)],
            'business_name' => ['nullable', 'string', 'max:255'], 'phone_number' => ['nullable', 'string', 'max:60'],
            'country' => ['nullable', 'string', 'max:100'], 'timezone' => ['required', 'timezone'],
            'profile_picture' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:800'],
        ]);
        if ($request->hasFile('profile_picture') && Schema::hasColumn('clients', 'profile_picture')) {
            if ($client->profile_picture) Storage::disk('public')->delete(ltrim(str_replace('/storage/', '', $client->profile_picture), '/'));
            $data['profile_picture'] = '/storage/'.$request->file('profile_picture')->store('clients/profile_pictures', 'public');
        }
        $client->update($data); $request->user()->update(['name' => $client->name, 'email' => $client->email]);
        return response()->json(['message' => 'Profile updated.', 'client' => $client->fresh()]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate(['current_password' => ['required'], 'password' => ['required', 'string', 'min:8', 'confirmed']]);
        if (!Hash::check($data['current_password'], $request->user()->password)) return response()->json(['message' => 'Current password is incorrect.', 'errors' => ['current_password' => ['Current password is incorrect.']]], 422);
        $request->user()->update(['password' => Hash::make($data['password'])]);
        return response()->json(['message' => 'Password changed.']);
    }

    private function client(Request $request): Client
    {
        return Client::where('user_id', $request->user()->id)->firstOrFail();
    }

    private function assertAssigned(Client $client, Employee $employee): void
    {
        abort_unless($client->employeeSchedules()->where('employee_id', $employee->id)->exists(), 403, 'This employee is not assigned to your account.');
    }
}
