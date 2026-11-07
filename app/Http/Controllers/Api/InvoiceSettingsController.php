<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BillingCycle;
use App\Services\ClientTimezoneService;
use App\Services\InvoiceAutoGenerationSchedule;
use Illuminate\Http\Request;

class InvoiceSettingsController extends Controller
{
    public function __construct(
        private InvoiceAutoGenerationSchedule $autoSchedule,
        private ClientTimezoneService $timezones,
    ) {
    }

    public function show()
    {
        return response()->json($this->payload());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'cycle_start_day' => ['required', 'integer', 'min:1', 'max:30'],
            'auto_generate' => ['required', 'boolean'],
        ]);

        BillingCycle::save($data);

        return response()->json([
            'message' => 'Invoice settings saved. Future automatic runs wait for the final cycle-end VA shift; existing invoices keep their periods.',
            'settings' => $this->payload(),
        ]);
    }

    private function payload(): array
    {
        $settings = BillingCycle::settings();
        $now = $this->timezones->now($this->timezones->businessTimezone());
        $openStart = BillingCycle::openStart($now);
        $periodEnd = BillingCycle::endOf($openStart)->toDateString();
        $scheduledFor = $this->autoSchedule->dueAt($periodEnd);

        return $settings + [
            'next_run_at' => $scheduledFor->toIso8601String(),
            'next_run_display_timezone' => $this->timezones->businessTimezone(),
            'generation_rule' => 'after_final_cycle_end_shift',
            'open_cycle_end_attendance' => $this->autoSchedule->openAttendanceCount($periodEnd),
        ];
    }
}
