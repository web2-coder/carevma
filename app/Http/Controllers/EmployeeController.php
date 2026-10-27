<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Role;
use App\Models\EmploymentType;
use App\Models\ShiftType;
use App\Models\Designation;
use App\Models\EmployeeStatus;
use App\Models\Expertise;
use App\Models\ReportingManager;
use App\Models\User;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Mail;
use App\Mail\CompleteProfileMail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Events\Notifications;

use DataTables;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function add_employee(Request $request)
    {
        $departments = Department::all();
        $roles = Role::all();
        $employmentTypes = EmploymentType::all();
        $shiftTypes = ShiftType::all();
        $designations = Designation::all();
        $employeeStatuses = EmployeeStatus::all();
        $expertises = Expertise::all();
        $reportingManagers = ReportingManager::all();
        $users = User::all();

        return view('admin.employee.add', compact(
            'departments',
            'roles',
            'employmentTypes',
            'shiftTypes',
            'designations',
            'employeeStatuses',
            'expertises',
            'reportingManagers',
            'users'
        ));
    }

    public function insert_employee(Request $request)
    {

        $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|unique:employees,email',
            'department' => 'required|integer',
            'role' => 'required|integer',
            'employee_type' => 'required|integer',
            'designation' => 'required|integer',
            'shift_type' => 'required|integer',
            'employee_image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'documents.*' => 'nullable|file|max:5120',
            'breaks' => 'required',
            'timezone' => 'nullable'

        ]);

        DB::beginTransaction();

        // Remove try/catch temporarily
        $employee = new Employee();
        $employee->first_name = $request->first_name;
        $employee->last_name = $request->last_name;
        $employee->email = $request->email;
        $employee->password = Hash::make($request->password);
        $employee->department_id = $request->department;
        $employee->role_id = $request->role;
        $employee->employment_type_id = $request->employee_type;
        $employee->employee_status_id = $request->employee_status;
        $employee->designation_id = $request->designation;
        $employee->shift_type_id = $request->shift_type;
        $employee->salary_pkr = $request->salary_pkr;
        $employee->salary_usd = $request->salary_usd;
        $employee->date_of_joining = $request->joining_date;
        $employee->break_allowed_hours = $request->breaks;
        $employee->timezone = $request->timezone;
        $employee->date_of_regularisation = $request->regularisation_date;

        if ($request->hasFile('employee_image')) {
            $employee->employee_image = $request->file('employee_image')->store('employees/images', 'public');
        }

        $user = User::create([
            'name' => $request->first_name . ' ' . $request->last_name,
            'email' => $request->email,
            'password' => Hash::make(Str::random(12)),
        ]);

        $employee->user_id = $user->id;
        $employee->save();

        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $file) {
                $employee->documents()->create([
                    'file_path' => $file->store('employees/documents', 'public'),
                ]);
            }
        }

        $token = Str::random(64);

        $temporaryUrl = URL::temporarySignedRoute(
            'employee.completeProfile',
            now()->addMinutes(5),
            ['email' => $user->email, 'token' => $token, 'employee_id' => $employee->id]
        );

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $name = $employee->first_name . ' ' . $employee->last_name;

        event(new Notifications([
            'id' => $employee->id,
            'name' => $name,
            'email' => $employee->email
        ], 'UserRegistered'));

        Mail::to($user->email)->send(new CompleteProfileMail($employee, $temporaryUrl));

        DB::commit();

        return view('admin.employee.index')->with('success', 'Employee added successfully!');
    }


    public function resendCompleteProfileEmail($id)
    {
        $employee = Employee::with('user')->findOrFail($id);
        $user = $employee->user;

        // generate new token
        $token = Str::random(64);

        $temporaryUrl = URL::temporarySignedRoute(
            'employee.completeProfile',
            now()->addMinutes(5),
            ['email' => $user->email, 'token' => $token]
        );

        // store token (replace old if exists)
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'email' => $user->email,
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        // send email again
        Mail::to($user->email)->send(new CompleteProfileMail($employee, $temporaryUrl));

        return redirect()->back()->with('success', 'Profile completion email resent successfully!');
    }



   public function showCompleteProfileForm(Request $request)
{

    $email = $request->query('email');
    $token = $request->query('token');

    $record = DB::table('password_reset_tokens')
        ->where('email', $email)
        ->first();

    if (!$record || !Hash::check($token, $record->token)) {
        return redirect()->route('login')
            ->with('error', 'Invalid or expired link.');
    }

    $employee = Employee::where('email', $email)->firstOrFail();

    return view('employee.after_register.index', compact('employee'));
}


public function submitCompleteProfile(Request $request)
{
    // Step 1: user clicked "Complete Profile"
    if ($request->step === 'start') {
        return view('employee.after_register.complete_profile', [
            'employee' => Employee::findOrFail($request->employee_id),
        ]);
    }

    // Step 2: final submission
    $request->validate([
        'password' => 'required|confirmed|min:8',
    ]);

    $employee = Employee::findOrFail($request->employee_id);

    $employee->user->update([
        'password' => Hash::make($request->password),
    ]);

    DB::table('password_reset_tokens')
        ->where('email', $employee->email)
        ->delete();

    return redirect()->route('login')
        ->with('success', 'Profile completed successfully.');
}



    public function all_employees(Request $request)
    {
        if ($request->ajax()) {
            $employees = Employee::with([
                'department',
                'role',
                'designation',
                'reportingManager',
                'shiftType',
                'employeeStatus',
                'expertise'
            ]);

            // Apply status filter
            if ($request->status) {
                $employees->where('employee_status_id', $request->status);
            }

            return DataTables::of($employees->get())
                ->addColumn('employee_info', function ($e) {
        $profilePic = $e->profile_picture
    ? asset('public/' . $e->profile_picture)
    : asset('profile.png'); // fallback if no image
    
                    $fullName = $e->first_name . ' ' . $e->last_name;

                    // Determine online/offline status
                    $isOnline = $e->user->last_seen_at && $e->user->last_seen_at->gt(now()->subMinutes(5));
                    $statusColor = $isOnline ? 'bg-success' : 'bg-secondary';
                    $statusTitle = $isOnline ? 'Online' : 'Offline';

                    return '
                    <a class="d-flex align-items-center text-decoration-none" href="' . route('edit.employee', $e->id) . '">
                    <div class="position-relative me-2">
                        <img src="' . $profilePic . '" 
                            class="rounded-circle" 
                            width="40" height="40" 
                            style="object-fit:cover;" />
                        <span class="position-absolute bottom-0 end-0 p-1 rounded-circle ' . $statusColor . '" 
                            title="' . $statusTitle . '" 
                            style="width:12px; height:12px; border:2px solid #fff;"></span>
                    </div>
                    <div class="d-flex flex-column">
                        <span class="fw-bold">' . e($fullName) . '</span>
                        <span style="text-transform:none;color:gray;">' . $e->email . '</span>
                    </div>
                </a>
                    ';
                })
                ->addColumn('department', fn($e) => $e->department->name ?? '-')
                ->addColumn('role', fn($e) => $e->role->name ?? '-')
                ->addColumn('designation', fn($e) => $e->designation->name ?? '-')
                ->addColumn('reporting_manager', fn($e) => $e->reportingManager->name ?? '-')
                ->addColumn('shift_type', fn($e) => $e->shiftType->name ?? '-')
                ->addColumn('status', fn($e) => $e->employeeStatus->name ?? '-')
                ->addColumn('expertise', fn($e) => $e->expertise->name ?? '-')
                ->addColumn('salary', function ($e) {
                    return '
                    <div>
                        <span><strong>Rs</strong> ' . $e->salary_pkr . '</span> <br>
                        <span><strong>$</strong> ' . $e->salary_usd . '</span>
                    </div>';
                })
                ->addColumn('actions', function ($e) {
                    $editUrl = route('edit.employee', $e->id);
                    $resendUrl = route('employee.resendProfileEmail', $e->id);

                    return '
                    <div class="btn-group">
                        <button type="button" class="cstm-dots-btn dropdown-toggle hide-arrow" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="ti tabler-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="' . $editUrl . '">
                                    <i class="ti tabler-edit me-1"></i> Edit
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item text-danger delete-employee" href="javascript:void(0);" 
                                   data-id="' . $e->id . '" 
                                   data-name="' . e($e->first_name . ' ' . $e->last_name) . '">
                                    <i class="ti tabler-trash me-1"></i> Delete
                                </a>
                            </li>
                            <li>
                                <form action="' . $resendUrl . '" method="POST" style="display:inline;">
                                    ' . csrf_field() . '
                                    <button type="submit" class="dropdown-item text-warning">
                                        <i class="ti tabler-mail me-1"></i> Resend Profile Email
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                ';
                })
                ->rawColumns(['salary', 'actions', 'employee_info'])
                ->make(true);
        }

        $statuses = EmployeeStatus::all();

        return view('admin.employee.index', compact('statuses'));
    }

    public function edit_employee($id)
    {
        $employee = Employee::findOrFail($id);

        return view('admin.employee.edit', [
            'employee' => $employee,
            'departments' => Department::all(),
            'roles' => Role::all(),
            'employmentTypes' => EmploymentType::all(),
            'designations' => Designation::all(),
            'shiftTypes' => ShiftType::all(),
            'employeeStatuses' => EmployeeStatus::all(),
            'expertises' => Expertise::all(),
            'reportingManagers' => ReportingManager::all(),
        ]);
    }

    public function update_employee(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:employees,email,' . $request->employee_id,
            'department' => 'required|exists:departments,id',
            'role' => 'required|exists:roles,id',
            'designation' => 'required|exists:designations,id',
            'employee_type' => 'required|exists:employment_types,id',
            'shift_type' => 'required|exists:shift_types,id',
            'employee_status' => 'required|exists:employee_statuses,id',
            'joining_date' => 'required|date',
            'timezone' => 'nullable'
            // Add other validation rules as needed
        ]);

        // Find the employee
        $employee = Employee::findOrFail($request->id);

        // Update employee data
        $employee->update([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'department_id' => $request->department,
            'role_id' => $request->role,
            'designation_id' => $request->designation,
            'employment_type_id' => $request->employee_type,
            'shift_type_id' => $request->shift_type,
            'employee_status_id' => $request->employee_status,
            'salary_pkr' => $request->salary_pkr,
            'salary_usd' => $request->salary_usd,
            'source_of_hire' => $request->source_of_hire,
            'date_of_joining' => $request->joining_date,
            'date_of_regularisation' => $request->regularisation_date,
            'expertise_id' => $request->current_expertise,
            'reporting_manager_id' => $request->reporting_manager,
            'gender' => $request->gender,
            'marital_status' => $request->marital_status,
            'age' => $request->age,
            'date_of_birth' => $request->birth_date,
            'about_me_notes' => $request->notes,
            'break_allowed_hours' => $request->breaks,
            'timezone' => $request->timezone ?? null,

        ]);


        return redirect()->route('edit.employee', $employee->id)
            ->with('success', 'Employee updated successfully.');
    }

    public function delete_employee($id)
    {
        $employee = Employee::findOrFail($id);

        if ($employee->profile_picture && \Storage::disk('public')->exists($employee->profile_picture)) {
            \Storage::disk('public')->delete($employee->profile_picture);
        }

        $employee->delete();

        return response()->json(['success' => true]);
    }

}




