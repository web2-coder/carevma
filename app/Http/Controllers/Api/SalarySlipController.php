<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SalarySlip;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SalarySlipController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = SalarySlip::query()->with([
            'employee:id,first_name,last_name,email,employee_code,department_id,designation_id,salary_pkr,salary_usd,user_id',
            'employee.department:id,name', 'employee.designation:id,name',
            'generator:id,name', 'approver:id,name',
        ])->latest('period_end')->latest('id');

        if ($user->role === 'employee') {
            $employeeId = Employee::where('user_id', $user->id)->value('id');
            $query->where('employee_id', $employeeId ?: 0)->where('status', 'approved');
        } else {
            abort_unless(in_array($user->role, ['admin', 'superadmin', 'hr'], true), 403);
            if ($request->filled('employee_id')) $query->where('employee_id', $request->integer('employee_id'));
            if ($request->filled('status')) $query->where('status', $request->string('status'));
            if ($search = trim((string) $request->query('search', ''))) {
                $query->whereHas('employee', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('employee_code', 'like', "%{$search}%");
                });
            }
        }

        return response()->json($query->paginate(10)->withQueryString());
    }

    public function store(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'superadmin', 'hr'], true), 403);
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'currency' => ['required', Rule::in(['PKR', 'USD'])],
            'base_amount' => ['nullable', 'numeric', 'min:0'],
            'bonus_amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        $currency = $data['currency'];
        $defaultBase = $currency === 'USD' ? $employee->salary_usd : $employee->salary_pkr;
        $base = round((float) ($data['base_amount'] ?? $defaultBase ?? 0), 2);
        $bonus = round((float) ($data['bonus_amount'] ?? 0), 2);
        $deduction = round((float) ($data['deduction_amount'] ?? 0), 2);
        $net = max(0, round($base + $bonus - $deduction, 2));

        $slip = SalarySlip::create([
            ...$data,
            'base_amount' => $base,
            'bonus_amount' => $bonus,
            'deduction_amount' => $deduction,
            'net_amount' => $net,
            'status' => 'draft',
            'generated_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Salary slip generated as draft.',
            'salary_slip' => $slip->load(['employee.department', 'employee.designation', 'generator']),
        ], 201);
    }

    public function update(Request $request, SalarySlip $salarySlip)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'superadmin', 'hr'], true), 403);
        abort_if($salarySlip->status === 'approved', 422, 'Approved salary slips are locked.');

        $data = $request->validate([
            'base_amount' => ['required', 'numeric', 'min:0'],
            'bonus_amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $base = round((float) $data['base_amount'], 2);
        $bonus = round((float) ($data['bonus_amount'] ?? 0), 2);
        $deduction = round((float) ($data['deduction_amount'] ?? 0), 2);
        $salarySlip->update([
            ...$data,
            'bonus_amount' => $bonus,
            'deduction_amount' => $deduction,
            'net_amount' => max(0, round($base + $bonus - $deduction, 2)),
        ]);

        return response()->json(['message' => 'Salary slip updated.', 'salary_slip' => $salarySlip->fresh()]);
    }

    public function approve(Request $request, SalarySlip $salarySlip)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'superadmin', 'hr'], true), 403);
        DB::transaction(function () use ($request, $salarySlip) {
            $salarySlip->lockForUpdate();
            $salarySlip->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
        });
        return response()->json(['message' => 'Salary slip approved.', 'salary_slip' => $salarySlip->fresh()]);
    }

    public function destroy(Request $request, SalarySlip $salarySlip)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'superadmin', 'hr'], true), 403);
        abort_if($salarySlip->status === 'approved', 422, 'Approved salary slips cannot be deleted.');
        $salarySlip->delete();
        return response()->json(['message' => 'Draft salary slip deleted.']);
    }

    public function pdf(Request $request, SalarySlip $salarySlip)
    {
        $user = $request->user();
        if ($user->role === 'employee') {
            $employeeId = Employee::where('user_id', $user->id)->value('id');
            abort_unless((int) $salarySlip->employee_id === (int) $employeeId && $salarySlip->status === 'approved', 403);
        } else {
            abort_unless(in_array($user->role, ['admin', 'superadmin', 'hr'], true), 403);
        }

        $salarySlip->load(['employee.department', 'employee.designation', 'generator', 'approver']);
        $filename = 'CAREVMA-SALARY-'.$salarySlip->employee->employee_code.'-'.$salarySlip->period_end->format('Ym').'.pdf';
        return Pdf::loadView('salary_slips.pdf', ['slip' => $salarySlip])
            ->setPaper('a4')
            ->download($filename);
    }
}
