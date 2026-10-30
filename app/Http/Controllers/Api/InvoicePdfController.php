<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

        $doctorName = Str::slug((string) ($invoice->client?->name ?: 'client'));
        $invoiceReference = Str::slug((string) $invoice->invoice_number);
        $filename = "carevma-{$doctorName}-{$invoiceReference}.pdf";

        $response = $this->documents
            ->make($invoice)
            ->stream($filename);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
