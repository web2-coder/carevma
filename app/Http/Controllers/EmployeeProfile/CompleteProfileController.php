<?php

namespace App\Http\Controllers\EmployeeProfile;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CompleteProfileController extends Controller
{
    private const INVITE_EMPLOYEE_KEY = 'carevma.employee_invite_id';

    public function welcome(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $request->query('email'))->first();
        $expired = !$record?->created_at || Carbon::parse($record->created_at)
            ->addMinutes((int) config('auth.passwords.users.expire', 60))
            ->isPast();

        if (!$record || $expired || !Hash::check((string) $request->query('token'), $record->token)) {
            return redirect()->route('login')->with('error', 'Invalid or expired profile invitation.');
        }

        $employee = Employee::where('email', $request->query('email'))->firstOrFail();
        $request->session()->put(self::INVITE_EMPLOYEE_KEY, $employee->id);

        return view('employee.after_register.index', compact('employee'));
    }

    public function start(Request $request, Employee $employee)
    {
        $this->assertEmployeeAccess($request, $employee);
        return view('employee.after_register.complete_profile', compact('employee'));
    }

    public function update(Request $request)
    {
        $employee = $this->employeeForRequest($request);

        if ($request->filled('employee_id') && (int) $request->input('employee_id') !== (int) $employee->id) {
            abort(403, 'This profile invitation does not belong to that employee.');
        }

        // Legacy AJAX field-save support is intentionally limited to employee-owned, self-service fields.
        if ($request->has('field')) {
            return $this->updateSingleField($request, $employee);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => [
                'required', 'email',
                Rule::unique('employees', 'email')->ignore($employee->id),
                Rule::unique('users', 'email')->ignore($employee->user_id),
            ],
            'phone' => ['required', 'string', 'max:60'],
            'gender' => ['required', Rule::in(['male', 'female', 'other'])],
            'marital_status' => ['required', Rule::in(['single', 'married'])],
            'age' => ['nullable', 'integer', 'min:16', 'max:100'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'about_me_notes' => ['nullable', 'string', 'max:500'],
            'profile_picture' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:800'],
            'upload_documents' => ['nullable', 'array', 'max:10'],
            'upload_documents.*' => ['file', 'mimes:jpg,jpeg,png,pdf,doc,docx', 'max:5120'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $data = collect($validated)->only([
            'first_name', 'last_name', 'email', 'phone', 'gender', 'marital_status', 'age',
            'date_of_birth', 'about_me_notes',
        ])->all();

        if ($request->hasFile('profile_picture')) {
            $path = $request->file('profile_picture')->store('employees/profile_pictures', 'public');
            $data['profile_picture'] = Storage::url($path);
        }

        if ($request->hasFile('upload_documents')) {
            $paths = [];
            foreach ($request->file('upload_documents') as $file) {
                $paths[] = Storage::url($file->store('employees/documents', 'public'));
            }
            $data['upload_documents'] = $paths;
        }

        $hashedPassword = Hash::make($validated['password']);
        $data['password'] = $hashedPassword;
        $employee->update($data);

        if ($employee->user_id) {
            User::whereKey($employee->user_id)->update([
                'name' => trim($employee->first_name.' '.$employee->last_name),
                'email' => $employee->email,
                'password' => $hashedPassword,
            ]);
        }

        $this->checkProfileCompletion($employee->fresh());
        $employee->refresh();

        if ($employee->profile_completed) {
            DB::table('password_reset_tokens')->where('email', $employee->email)->delete();
            return redirect()->route('employee.completed', ['employee' => $employee->id]);
        }

        return redirect()->back()->with('error', 'Please complete all required fields before proceeding.');
    }

    public function profile_completed(Request $request, Employee $employee)
    {
        $this->assertEmployeeAccess($request, $employee);
        abort_unless($employee->profile_completed, 403, 'Profile completion is still pending.');
        return view('employee.after_register.profile_done', compact('employee'));
    }

    public function autoLogin(Request $request, Employee $employee)
    {
        $this->assertEmployeeAccess($request, $employee);
        abort_unless($employee->profile_completed && $employee->user, 403);

        Auth::login($employee->user);
        $request->session()->forget(self::INVITE_EMPLOYEE_KEY);
        $request->session()->regenerate();

        return redirect()->route('employee.dashboard');
    }

    private function updateSingleField(Request $request, Employee $employee)
    {
        $field = (string) $request->input('field');

        if ($field === 'profile_picture' && $request->hasFile('value')) {
            $request->validate(['value' => ['image', 'mimes:jpg,jpeg,png', 'max:800']]);
            $path = $request->file('value')->store('employees/profile_pictures', 'public');
            $employee->update(['profile_picture' => Storage::url($path)]);
            return response()->json(['success' => true, 'message' => 'Profile picture updated.', 'value' => asset(Storage::url($path))]);
        }

        if ($field === 'upload_documents' && $request->hasFile('value')) {
            $request->validate(['value' => ['file', 'mimes:jpg,jpeg,png,pdf,doc,docx', 'max:5120']]);
            $path = Storage::url($request->file('value')->store('employees/documents', 'public'));
            $documents = $this->documents($employee->upload_documents);
            $documents[] = $path;
            $employee->update(['upload_documents' => $documents]);
            return response()->json(['success' => true, 'message' => 'Document uploaded.', 'value' => asset($path)]);
        }

        $allowed = ['first_name', 'last_name', 'email', 'phone', 'gender', 'marital_status', 'age', 'date_of_birth', 'about_me_notes'];
        abort_unless(in_array($field, $allowed, true), 422, 'That field cannot be updated through self service.');

        $rules = match ($field) {
            'first_name', 'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', Rule::unique('employees', 'email')->ignore($employee->id), Rule::unique('users', 'email')->ignore($employee->user_id)],
            'phone' => ['required', 'string', 'max:60'],
            'gender' => ['required', Rule::in(['male', 'female', 'other'])],
            'marital_status' => ['required', Rule::in(['single', 'married'])],
            'age' => ['nullable', 'integer', 'min:16', 'max:100'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            default => ['nullable', 'string', 'max:500'],
        };
        $validated = $request->validate(['value' => $rules]);
        $employee->update([$field => $validated['value']]);

        if (in_array($field, ['first_name', 'last_name', 'email'], true) && $employee->user) {
            $employee->user->update([
                'name' => trim($employee->first_name.' '.$employee->last_name),
                'email' => $employee->email,
            ]);
        }

        return response()->json(['success' => true, 'message' => ucfirst(str_replace('_', ' ', $field)).' updated.']);
    }

    private function employeeForRequest(Request $request): Employee
    {
        if (Auth::check()) {
            return Employee::where('user_id', Auth::id())->firstOrFail();
        }

        $employeeId = (int) $request->session()->get(self::INVITE_EMPLOYEE_KEY, 0);
        abort_unless($employeeId > 0, 403, 'Profile invitation session has expired.');
        return Employee::findOrFail($employeeId);
    }

    private function assertEmployeeAccess(Request $request, Employee $employee): void
    {
        if (Auth::check()) {
            abort_unless((int) $employee->user_id === (int) Auth::id(), 403);
            return;
        }

        abort_unless((int) $request->session()->get(self::INVITE_EMPLOYEE_KEY, 0) === (int) $employee->id, 403, 'Profile invitation session has expired.');
    }

    private function checkProfileCompletion(Employee $employee): void
    {
        // Keep legacy invitation/profile screens aligned with the React onboarding rule.
        $employee->profile_completed = $employee->hasCompleteSelfServiceProfile();
        $employee->save();
    }

    private function documents(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!$value) return [];
        $decoded = json_decode($value, true);
        if (is_string($decoded)) $decoded = json_decode($decoded, true);
        return is_array($decoded) ? $decoded : [];
    }
}
