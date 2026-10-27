<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Invoice;
use App\Models\ClientEmployeeRate;
use Illuminate\Http\Request;
use PDF;
use Carbon\Carbon;
use Yajra\DataTables\Facades\DataTables;

class InvoicesController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $invoices = Invoice::with('client');

            return DataTables::of($invoices)
                ->addColumn('client', function ($row) {
                    return $row->client ? $row->client->name : '-';
                })
                ->addColumn('period_from', function ($row) {
                    return Carbon::parse($row->period_start)->format('j M, y');
                })
                ->addColumn('period_to', function ($row) {
                    return Carbon::parse($row->period_end)->format('j M, y');
                })
                ->addColumn('action', function ($row) {
                    $url = route('invoices.pdf', $row->id);
                    $editUrl = route('invoices.edit', $row->id);
                    return '
                    <div class="btn-group">
                        <button type="button" class="cstm-dots-btn dropdown-toggle hide-arrow" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="ti tabler-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="' . $url . '" target="_blank">
                                    <i class="ti tabler-eye me-1"></i> View
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="' . $editUrl . '">
                                    <i class="ti tabler-edit me-1"></i> Edit
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item text-danger delete-invoice" href="javascript:void(0);" data-id="' . $row->id . '">
                                    <i class="ti tabler-trash me-1"></i> Delete
                                </a>
                            </li>
                        </ul>
                    </div>
                    ';
                })
                ->rawColumns(['action'])
                ->make(true);
        }
        return view('admin.invoice.index');
    }

    public function add_invoice(Request $request)
    {
        $clients = Client::all();
        $employees = Employee::all();
        return view('admin.invoice.add', compact(['clients', 'employees']));
    }

    public function storeInvoice(Request $request)
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'employees' => 'required|array',
            'employees.*.employee_id' => 'required|exists:employees,id',
            'employees.*.total_hours' => 'required|numeric|min:0',
            'employees.*.salary_amount' => 'required|numeric|min:0',
            'employees.*.total_late' => 'nullable|numeric|min:0',
            'employees.*.total_overtime' => 'nullable|numeric|min:0',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);

        // Generate one invoice number for all employees
        $invoiceNumber = 'INV-' . str_pad(mt_rand(0, 99999), 5, '0', STR_PAD_LEFT);

        // Calculate totals
        $totalHours = 0;
        $totalAmount = 0;
        foreach ($request->employees as $emp) {
            $totalHours += $emp['total_hours'];
            $totalAmount += $emp['salary_amount'];
        }

        // Create invoice in invoices table
        $invoice = Invoice::create([
            'client_id' => $request->client_id,
            'invoice_number' => $invoiceNumber,
            'period_start' => $request->period_start,
            'period_end' => $request->period_end,
            'total_hours' => $totalHours,
            'total_amount' => $totalAmount,
        ]);

        // Store each employee salary linked to invoice
        foreach ($request->employees as $empData) {
            EmployeeSalary::create([
                'invoice_id' => $invoice->id,
                'client_id' => $request->client_id,
                'employee_id' => $empData['employee_id'],
                'total_hours' => $empData['total_hours'],
                'salary_amount' => $empData['salary_amount'],
                'total_late' => $empData['total_late'] ?? 0,
                'total_overtime' => $empData['total_overtime'] ?? 0,
                'period_start' => $request->period_start,
                'period_end' => $request->period_end,
            ]);
        }

        return redirect()->route('admin.invoices')->with('success', 'Invoice created successfully!');
    }

    public function generateInvoicePdf($id)
{
    // Get invoice with basic relationships
    $invoice = Invoice::with(['client', 'salaries.employee'])->findOrFail($id);
    
    // Load attendances for each employee filtered by invoice period
    foreach ($invoice->salaries as $salary) {
        $salary->employee->load(['attendances' => function($query) use ($invoice) {
            $query->whereBetween('date', [$invoice->period_start, $invoice->period_end])
                  ->orderBy('date', 'asc');
        }]);
    }

    // Get client employee rates
    $clientEmployeeRates = ClientEmployeeRate::where('client_id', $invoice->client_id)
        ->get()
        ->keyBy('employee_id');

    // Generate PDF
    $pdf = PDF::loadView('invoices.invoice', [
        'invoice' => $invoice,
        'client' => $invoice->client,
        'salaries' => $invoice->salaries,
        'clientEmployeeRates' => $clientEmployeeRates,
    ]);

    return $pdf->stream("invoice-{$invoice->invoice_number}.pdf");
}

    public function edit($id)
    {
        $invoice = Invoice::with(['client', 'salaries.employee'])->findOrFail($id);

        return view('admin.invoice.edit', compact('invoice'));
    }
    
    public function update(Request $request, $id)
    {
        $invoice = Invoice::with('salaries')->findOrFail($id);
    
        $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
            'employees'    => 'required|array',
        ]);
    
        // Update employee salaries
        $totalHours = 0;
        $totalAmount = 0;
    
        foreach ($request->employees as $empData) {
            if (!empty($empData['id'])) {
                $salary = EmployeeSalary::find($empData['id']);
    
                if ($salary) {
                    $salary->update([
                        'total_hours'    => $empData['total_hours'] ?? 0,
                        'total_late'     => $empData['total_late'] ?? 0,
                        'total_overtime' => $empData['total_overtime'] ?? 0,
                        'salary_amount'  => $empData['salary_amount'] ?? 0,
                        'period_start'   => $request->period_start,
                        'period_end'     => $request->period_end,
                    ]);
    
                    // accumulate invoice totals
                    $totalHours  += $salary->total_hours;
                    $totalAmount += $salary->salary_amount;
                }
            }
        }
    
        // Update invoice fields
        $invoice->update([
            'period_start' => $request->period_start,
            'period_end'   => $request->period_end,
            'total_hours'  => $totalHours,
            'total_amount' => $totalAmount,
        ]);
    
        return redirect()
            ->route('admin.invoices')
            ->with('success', 'Invoice and salaries updated successfully!');
    }
}