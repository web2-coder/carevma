<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class InvoicePdfController extends Controller
{
    public function __construct(private InvoicePdfService $documents)
    {
    }

    public function __invoke(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'superadmin'], true)) abort(403);

        $invoice->loadMissing('client');

        $doctor = Str::of($invoice->client?->name ?: 'Doctor')
            ->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-')->value() ?: 'Doctor';
        $monthYear = Carbon::parse($invoice->period_end)->format('F-Y');
        $filename = $doctor.'-Invoice-'.$monthYear.'.pdf';

        $response = $this->documents
            ->make($invoice)
            ->stream($filename);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
