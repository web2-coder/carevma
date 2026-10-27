<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;


class DoctorController extends Controller
{
    /**
     * Doctor Dashboard (Assigned Employees)
     */
    public function dashboard()
    {
        $client = Auth::user()->client;

        if (!$client) {
            return view('doctor.dashboard.index')->with([
                'employees' => collect(),
                'client' => null,
            ]);
        }

        $employees = $client->employees()
            ->with(['department', 'role', 'designation', 'shiftType', 'employeeStatus', 'expertise', 'reportingManager'])
            ->distinct()
            ->get();

        return view('doctor.dashboard.index', compact('employees', 'client')); // ✅ pass client
    }
    /**
     * Attendance Page — Employee Detail Page
     */
    public function employeeAttendance(Employee $employee)
    {
        $client = Auth::user()->client;
        if (!$client || !$client->employees->contains($employee)) {
            abort(403, "Unauthorized access.");
        }

        $attendances = $employee->attendances()->with('breaks')->get();

        return view('doctor.employee.attendance', compact('employee', 'attendances', 'client')); // ✅ pass client
    }
    /**
     * DataTable JSON — attendance records
     */
public function attendanceData(Employee $employee)
{
    $client = Auth::user()->client;
    if (!$client || !$client->employees->contains($employee)) {
        abort(403, "Unauthorized access.");
    }

    $query = Attendance::where('employee_id', $employee->id);

    if(request()->has('from_date') && request('from_date') != ''){
        $query->where('date', '>=', request('from_date'));
    }

    if(request()->has('to_date') && request('to_date') != ''){
        $query->where('date', '<=', request('to_date'));
    }

    $attendances = $query->orderBy('date', 'desc')->get();

    return datatables()->of($attendances)
        ->addIndexColumn()
        ->editColumn('date', fn($a) => $a->date->format('Y-m-d'))
        ->editColumn('clock_in', fn($a) => $a->clock_in ? $a->clock_in->format('H:i') : '-')
        ->editColumn('clock_out', fn($a) => $a->clock_out ? $a->clock_out->format('H:i') : '-')
        ->editColumn('break_taken', fn($a) => $a->break_taken . ' mins')
        ->editColumn('total_minutes', fn($a) => $a->total_minutes ?? '0')
        ->editColumn('overtime', fn($a) => $a->overtime ?? '0')
        ->make(true);
}





 // 🔹 My Profile Page
    public function profile()
    {
        $user = Auth::user();
        $client = $user->client; // for doctor profile

        if (!$client) {
            abort(404, "Profile not found.");
        }

        return view('doctor.profile.index', compact('client'));
    }

    // 🔹 Update Profile
    public function profileUpdate(Request $request)
    {
        $user = Auth::user();
        $client = $user->client;

        if (!$client) {
            abort(404, "Profile not found.");
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:clients,email,'.$client->id,
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $client->name = $request->name;
        $client->email = $request->email;

        // profile picture
        if ($request->hasFile('profile_picture')) {
            // delete old pic
            if ($client->profile_picture && Storage::exists($client->profile_picture)) {
                Storage::delete($client->profile_picture);
            }

            $path = $request->file('profile_picture')->store('client_profiles');
            $client->profile_picture = $path;
        }

        $client->save();

        return redirect()->back()->with('success', 'Profile updated successfully.');
    }

    // 🔹 Change Password (optional)
    public function changePassword(Request $request)
    {
        $user = Auth::user();
        $request->validate([
            'current_password' => 'required',
            'password' => 'required|min:6|confirmed',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return redirect()->back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return redirect()->back()->with('success', 'Password changed successfully.');
    }



}
