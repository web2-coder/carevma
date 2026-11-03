<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BillingCycle;
use Illuminate\Http\Request;

class InvoiceSettingsController extends Controller
{
    public function show()
    {
        return response()->json(BillingCycle::settings());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'cycle_start_day' => ['required', 'integer', 'min:1', 'max:28'],
            'generation_time' => ['required', 'date_format:H:i'],
            'auto_generate' => ['required', 'boolean'],
        ]);

        return response()->json([
            'message' => 'Invoice settings saved. Future automatic runs use the new cycle; existing invoices keep their periods.',
            'settings' => BillingCycle::save($data),
        ]);
    }
}
