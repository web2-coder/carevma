<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientEmployeeRate;
use App\Models\Invoice;
use App\Services\ClientTimezoneService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class InvoicePdfController extends Controller
{
    public function __construct(private ClientTimezoneService $timezones)
    {
    }

    public function __invoke(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        // Invoice documents are intentionally Admin-only. Client/Doctor accounts do not have invoice access.
        if (!in_array($user->role, ['admin', 'superadmin'], true)) abort(403);

        // Always reload the latest invoice state before rendering so adjustments added
        // from Edit Invoice are immediately reflected in the PDF.
        $invoice = $invoice->fresh();
        $invoice->load([
            'client',
            'salaries.employee',
            'adjustments.employee:id,first_name,last_name,employee_code',
        ]);

        $employeeIds = $invoice->salaries->pluck('employee_id')->filter()->unique()->values();
        $attendanceByEmployee = collect();
        if ($employeeIds->isNotEmpty()) {
            [$startUtc, $endUtc] = $this->timezones->utcBoundsForLocalDates(
                $invoice->period_start->toDateString(),
                $invoice->period_end->toDateString(),
                $invoice->client
            );
            $attendanceQuery = \App\Models\Attendance::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('clock_in', [$startUtc, $endUtc])
                ->whereNotNull('clock_out')
                ->orderBy('clock_in');
            if (Schema::hasColumn('attendances', 'client_id')) {
                $attendanceQuery->where('client_id', $invoice->client_id);
            }
            $attendanceByEmployee = $attendanceQuery->get()->groupBy('employee_id');
        }

        foreach ($invoice->salaries as $salary) {
            if ($salary->employee) {
                $salary->employee->setRelation('attendances', $attendanceByEmployee->get($salary->employee_id, collect()));
            }
        }

        $rates = ClientEmployeeRate::where('client_id', $invoice->client_id)->get()->keyBy('employee_id');
        $clientTimezone = $this->timezones->forClient($invoice->client);
        $response = Pdf::loadView('invoices.invoice', [
            'invoice' => $invoice,
            'client' => $invoice->client,
            'salaries' => $invoice->salaries,
            'clientEmployeeRates' => $rates,
            'clientTimezone' => $clientTimezone,
        ])->stream("invoice-{$invoice->invoice_number}.pdf");

        // Prevent browsers/proxies from serving a pre-edit cached PDF.
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
