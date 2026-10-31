<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ strtoupper($invoice->invoice_number) }}</title>
    <style>
        @page { margin: 28px 30px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; margin: 0; padding: 0; color: #333; }
        .invoice-box { width: 100%; }
        .header { border-bottom: 2px solid #083589; padding-bottom: 10px; margin-bottom: 18px; }
        .header img { width: 245px; max-width: 245px; height: auto; display: block; }
        .flex { display: table; width: 100%; }
        .flex > div { display: table-cell; vertical-align: top; width: 50%; }
        .section-title { background: #083589; color: #fff; padding: 7px; font-weight: bold; margin-top: 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 9px; }
        table th, table td { border: 1px solid #ccc; padding: 7px; text-align: left; }
        table th { background: #f2f2f2; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .subtotal-row td { font-weight: bold; background: #f7f9f9; }
        .page-break { page-break-after: always; }
        .attendance-section { margin-bottom: 24px; page-break-inside: auto; }
        .payment-page { margin-top: 18px; page-break-inside: avoid; }
        .payment-card { border: 1px solid #d7dfdd; padding: 18px; page-break-inside: avoid; }
        .grand-total { background: #083589; color: #fff; padding: 14px 16px; margin-bottom: 18px; page-break-inside: avoid; }
        .grand-total table { margin: 0; }
        .grand-total table td { border: 0; color: #fff; padding: 2px 0; font-size: 16px; }
        .account-details { border: 1px solid #d7dfdd; padding: 14px 16px; page-break-inside: avoid; }
        .account-details p { margin: 5px 0; }
        .note { font-size: 10px; line-height: 1.45; color: #555; margin-top: 14px; page-break-inside: avoid; }
        .footer { text-align: center; font-weight: bold; color: #083589; margin-top: 20px; }
        .meta-line { margin: 4px 0; }
    </style>
</head>
<body>
@php
    $clientTimezone = $clientTimezone ?? ($invoice->client->timezone ?: 'UTC');
    $carevmaLogoPath = public_path('images/CareVMA-full-logo.png');
    $carevmaLogoSrc = file_exists($carevmaLogoPath)
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($carevmaLogoPath))
        : null;
    $serviceName = $invoice->client?->service?->name ?: 'Medical VA Service';
    $doctorType = $invoice->client?->contractType?->name ?: 'Not specified';
    $generatedLocal = \Carbon\Carbon::parse($invoice->generated_at ?: $invoice->created_at)->setTimezone($clientTimezone);
    // Bill month follows the billing cycle end date.
    // Example: 25 Sep 2026 - 29 Oct 2026 => OCTOBER 2026.
    $billingMonthDate = \Carbon\Carbon::parse($invoice->period_end)->setTimezone($clientTimezone);
    $baseSubtotal = (float) $invoice->salaries->sum('salary_amount');
    $adjustmentNet = $invoice->adjustments->sum(function ($item) {
        return $item->direction === 'deduction' ? -((float) $item->amount) : (float) $item->amount;
    });
@endphp

<div class="invoice-box">
    <div class="header flex">
        <div>
            @if($carevmaLogoSrc)
                <img src="{{ $carevmaLogoSrc }}" alt="CareVMA">
            @else
                <div style="font-size:28px;font-weight:700;color:#153a8a;">CARE <span style="color:#48cfdd;">VMA</span></div>
            @endif
        </div>
        <div style="text-align:right;"><h3 style="margin:0;color:#083589;">INVOICE</h3></div>
    </div>

    <div class="flex">
        <div>
            <p class="meta-line"><strong>INVOICE #:</strong> {{ strtoupper($invoice->invoice_number) }}</p>
            <p class="meta-line"><strong>Date:</strong> {{ $generatedLocal->format('d F Y') }}</p>
            <p class="meta-line"><strong>Cycle:</strong> {{ \Carbon\Carbon::parse($invoice->period_start)->format('d M Y') }} - {{ \Carbon\Carbon::parse($invoice->period_end)->format('d M Y') }}</p>
        </div>
        <div style="text-align:right;">
            <p class="meta-line"><strong>FOR:</strong> {{ $serviceName }}</p>
            <p class="meta-line"><strong>DOCTOR TYPE:</strong> {{ $doctorType }}</p>
            <p class="meta-line"><strong>BILL MONTH:</strong> {{ strtoupper($billingMonthDate->format('F Y')) }}</p>
        </div>
    </div>

    <div class="section-title">BILL TO</div>
    <p><strong>{{ $invoice->client->name }}</strong></p>
    @if($invoice->client->address ?? null)<p>{{ $invoice->client->address }}</p>@endif
    @if($invoice->client->email ?? null)<p>{{ $invoice->client->email }}</p>@endif

    <div class="section-title">ITEM DESCRIPTION</div>
    <table>
        <thead>
            <tr>
                <th>VA Name</th>
                <th>Working Hours</th>
                <th>Rate/h</th>
                <th>From</th>
                <th>To</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
        @foreach($invoice->salaries as $salary)
            @php
                $employee = $salary->employee;
                $rate = isset($clientEmployeeRates[$salary->employee_id]) ? $clientEmployeeRates[$salary->employee_id]->per_hour_rate : 0;
                $attendanceRows = $employee?->attendances ?? collect();
                $regularMinutes = max(0, (int) $attendanceRows->sum('total_minutes'));
                $approvedOtMinutes = max(0, (int) $attendanceRows->sum('approved_overtime_minutes'));
                $payableMinutes = $regularMinutes + $approvedOtMinutes;
                $formatMinutes = function (int $minutes): string {
                    $minutes = max(0, $minutes);
                    $hours = intdiv($minutes, 60);
                    $mins = $minutes % 60;
                    return $hours > 0 ? sprintf('%dh %02dm', $hours, $mins) : sprintf('%dm', $mins);
                };
            @endphp
            <tr>
                <td>{{ $employee ? trim($employee->first_name.' '.$employee->last_name) : 'Employee #'.$salary->employee_id }}</td>
                <td>{{ $formatMinutes($payableMinutes) }}</td>
                <td>${{ number_format($rate, 2) }}</td>
                <td>{{ \Carbon\Carbon::parse($salary->period_start)->format('d-M') }}</td>
                <td>{{ \Carbon\Carbon::parse($salary->period_end)->format('d-M') }}</td>
                <td class="text-right">${{ number_format($salary->salary_amount, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @if($invoice->adjustments->isNotEmpty())
        <div class="section-title">INVOICE ADJUSTMENTS</div>
        <table>
            <thead>
                <tr><th>VA / Applies To</th><th>Type</th><th>Category</th><th>Description</th><th class="text-right">Amount</th></tr>
            </thead>
            <tbody>
            @foreach($invoice->adjustments as $adjustment)
                @php
                    $isDeduction = $adjustment->direction === 'deduction';
                    $employeeLabel = $adjustment->employee
                        ? trim($adjustment->employee->first_name . ' ' . $adjustment->employee->last_name)
                        : 'Whole invoice';
                @endphp
                <tr>
                    <td>{{ $employeeLabel }}</td>
                    <td><strong>{{ $isDeduction ? 'Deduction' : 'Addition' }}</strong></td>
                    <td>{{ ucwords(str_replace('_', ' ', $adjustment->category)) }}</td>
                    <td>{{ $adjustment->description ?: '-' }}</td>
                    <td class="text-right"><strong>{{ $isDeduction ? '-' : '+' }}${{ number_format((float) $adjustment->amount, 2) }}</strong></td>
                </tr>
            @endforeach
                <tr class="subtotal-row">
                    <td colspan="4" class="text-right">Net adjustments</td>
                    <td class="text-right">{{ $adjustmentNet < 0 ? '-' : '+' }}${{ number_format(abs($adjustmentNet), 2) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <table style="margin-top:10px;">
        <tbody>
            <tr class="subtotal-row">
                <td class="text-right">Subtotal</td>
                <td class="text-right" style="width:160px;">${{ number_format($baseSubtotal, 2) }}</td>
            </tr>
        </tbody>
    </table>
</div>

<div class="payment-page">
    <div class="payment-card">
        <h2 style="margin:0 0 12px;color:#083589;">Payment & Bank Details</h2>
        <p>Please pay via Payoneer or to the account given below.</p>
        <div class="section-title" style="margin-top:14px;">ACCOUNT DETAILS</div>
        <div class="account-details">
            <p><strong>Bank Name:</strong> Citibank</p>
            <p><strong>Bank Address:</strong> 111 Wall Street New York, NY 10043 USA</p>
            <p><strong>Routing (ABA):</strong> 031100209</p>
            <p><strong>SWIFT Code:</strong> CITIUS33</p>
            <p><strong>Account No:</strong> 7508040001159007</p>
            <p><strong>Account type:</strong> CHECKING</p>
            <p><strong>Beneficiary name:</strong> Carevma Health</p>
        </div>
        <p class="note">
            Note: THIS ACCOUNT ONLY ACCEPTS PAYMENTS FROM BUSINESS ACCOUNTS.
            PAYMENTS MADE FROM PERSONAL ACCOUNTS WILL BE RETURNED AUTOMATICALLY.
            FOR PAYING FROM PERSONAL ACCOUNTS PLEASE USE ALTERNATE METHODS.
        </p>
        <div class="footer">THANK YOU FOR YOUR BUSINESS!</div>
    </div>
</div>

<div class="page-break"></div>

<div class="invoice-box">
    <h2 style="color:#083589;">Attendance Records</h2>
    <p><strong>Period:</strong> {{ \Carbon\Carbon::parse($invoice->period_start)->format('d F Y') }} - {{ \Carbon\Carbon::parse($invoice->period_end)->format('d F Y') }}</p>
    {{-- <p><strong>Timezone:</strong> {{ $clientTimezone }}</p> --}}

    @foreach($invoice->salaries as $salary)
        <div class="attendance-section">
            @php
                $employee = $salary->employee;
                $employeeAttendanceRows = $employee?->attendances ?? collect();
                $formatAttendanceMinutes = function (int $minutes): string {
                    $minutes = max(0, $minutes);
                    $hours = intdiv($minutes, 60);
                    $mins = $minutes % 60;
                    return $hours > 0 ? sprintf('%dh %02dm', $hours, $mins) : sprintf('%dm', $mins);
                };
                $regularTotal = max(0, (int) $employeeAttendanceRows->sum('total_minutes'));
                $approvedOtTotal = max(0, (int) $employeeAttendanceRows->sum('approved_overtime_minutes'));
                $payableTotal = $regularTotal + $approvedOtTotal;
            @endphp
            <h4 style="background:#f2f2f2;padding:8px;margin-top:20px;">
                {{ $employee ? trim($employee->first_name.' '.$employee->last_name) : 'Employee #'.$salary->employee_id }}
                (Working Hours: {{ $formatAttendanceMinutes($payableTotal) }})
            </h4>
            <table style="font-size:8px;">
                <thead>
                    <tr>
                        <th>Date</th><th>Day</th><th>Status</th><th>Shift(s)</th><th>Clock In</th><th>Clock Out</th><th>Break</th><th>Total Hours</th><th>Overtime</th><th>Payable Hours</th>
                    </tr>
                </thead>
                <tbody>
                @php
                    $periodCursor = \Carbon\Carbon::parse($salary->period_start ?: $invoice->period_start)->startOfDay();
                    $periodLast = \Carbon\Carbon::parse($invoice->period_end)->startOfDay();
                    $employeeAttendances = $employeeAttendanceRows;
                    $employeeSchedules = ($scheduleByEmployee ?? collect())->get($salary->employee_id, collect());
                    $attendanceByDate = $employeeAttendances->groupBy(function ($item) use ($clientTimezone) {
                        if ($item->clock_in) {
                            return \Carbon\Carbon::parse($item->clock_in)->setTimezone($clientTimezone)->format('Y-m-d');
                        }
                        return \Carbon\Carbon::parse($item->date)->format('Y-m-d');
                    });

                    // If this VA is assigned only on specific one-time dates, show only
                    // those assigned dates (plus matching attendance dates) on the PDF.
                    // Do not mark every other day in the invoice cycle as OFF/ABSENT.
                    $hasRecurringSchedule = $employeeSchedules->contains(function ($schedule) {
                        return empty(data_get($schedule, 'schedule_date'));
                    });
                    $oneTimeDateKeys = $employeeSchedules
                        ->filter(fn ($schedule) => !empty(data_get($schedule, 'schedule_date')))
                        ->map(fn ($schedule) => \Carbon\Carbon::parse(data_get($schedule, 'schedule_date'))->format('Y-m-d'))
                        ->filter(fn ($date) => $date >= $periodCursor->format('Y-m-d') && $date <= $periodLast->format('Y-m-d'))
                        ->values();

                    if (!$hasRecurringSchedule && $oneTimeDateKeys->isNotEmpty()) {
                        $displayDateKeys = $oneTimeDateKeys
                            ->merge($attendanceByDate->keys())
                            ->unique()
                            ->sort()
                            ->values();
                    } else {
                        $displayDateKeys = collect();
                        $cursor = $periodCursor->copy();
                        while ($cursor->lte($periodLast)) {
                            $displayDateKeys->push($cursor->format('Y-m-d'));
                            $cursor->addDay();
                        }
                    }
                @endphp

                @foreach($displayDateKeys as $dateKey)
                    @php
                        $displayDate = \Carbon\Carbon::parse($dateKey);
                        $dayName = $displayDate->format('l');
                        $dayAttendances = $attendanceByDate->get($dateKey, collect());
                        $daySchedules = $employeeSchedules->filter(function ($schedule) use ($dateKey, $dayName) {
                            if (!empty(data_get($schedule, 'schedule_date'))) {
                                return \Carbon\Carbon::parse(data_get($schedule, 'schedule_date'))->format('Y-m-d') === $dateKey;
                            }
                            return strcasecmp((string) data_get($schedule, 'weekday'), $dayName) === 0;
                        })->values();
                    @endphp

                    @if($daySchedules->isEmpty() && $dayAttendances->isEmpty())
                        <tr style="background:#eeeeee;">
                            <td>{{ $displayDate->format('d-M-Y') }}</td>
                            <td>{{ $dayName }}</td>
                            <td colspan="8" style="text-align:center;font-weight:700;color:#666;padding:8px 4px;">OFF</td>
                        </tr>
                    @elseif($dayAttendances->isEmpty())
                        @php
                            $shiftLabel = $daySchedules->map(function ($schedule) {
                                $start = \Carbon\Carbon::parse(data_get($schedule, 'start_time'))->format('h:i A');
                                $end = \Carbon\Carbon::parse(data_get($schedule, 'end_time'))->format('h:i A');
                                return $start.' - '.$end;
                            })->implode(', ');
                        @endphp
                        <tr>
                            <td>{{ $displayDate->format('d-M-Y') }}</td>
                            <td>{{ $dayName }}</td>
                            <td style="font-weight:700;">ABSENT</td>
                            <td>{{ $shiftLabel ?: '-' }}</td>
                            <td>-</td><td>-</td><td>-</td><td>0m</td><td>0m</td><td>0m</td>
                        </tr>
                    @else
                        @foreach($dayAttendances as $attendance)
                            @php
                                $timezone = $clientTimezone;
                                $clockInLocal = $attendance->clock_in ? \Carbon\Carbon::parse($attendance->clock_in)->setTimezone($timezone) : null;
                                $clockOutLocal = $attendance->clock_out ? \Carbon\Carbon::parse($attendance->clock_out)->setTimezone($timezone) : null;
                                $attendanceDate = $clockInLocal ? $clockInLocal->format('d-M-Y') : $displayDate->format('d-M-Y');
                                $clockIn = $clockInLocal ? $clockInLocal->format('h:i A') : '-';
                                $clockOut = $clockOutLocal ? $clockOutLocal->format('h:i A') : '-';
                                $breakMinutes = (int) floor(max(0, (int) ($attendance->break_taken ?? 0)) / 60);
                                $regularMinutes = max(0, (int) ($attendance->total_minutes ?? 0));
                                $overtimeMinutes = max(0, (int) ($attendance->overtime ?? 0));
                                $approvedMinutes = max(0, (int) ($attendance->approved_overtime_minutes ?? 0));
                                $payableMinutes = $regularMinutes + ($attendance->overtime_status === 'approved' ? $approvedMinutes : 0);
                                $elapsedMinutes = ($clockInLocal && $clockOutLocal) ? max(0, $clockInLocal->diffInMinutes($clockOutLocal)) : 0;
                                $actualWorkedMinutes = max(0, $elapsedMinutes - $breakMinutes);
                                $shiftStartLocal = $attendance->shift_start_snapshot ? \Carbon\Carbon::parse($attendance->shift_start_snapshot)->setTimezone($timezone) : null;
                                $shiftEndLocal = $attendance->shift_end_snapshot ? \Carbon\Carbon::parse($attendance->shift_end_snapshot)->setTimezone($timezone) : null;
                                $shiftLabel = ($shiftStartLocal && $shiftEndLocal)
                                    ? $shiftStartLocal->format('h:i A').' - '.$shiftEndLocal->format('h:i A')
                                    : $daySchedules->map(function ($schedule) {
                                        return \Carbon\Carbon::parse(data_get($schedule, 'start_time'))->format('h:i A').' - '.\Carbon\Carbon::parse(data_get($schedule, 'end_time'))->format('h:i A');
                                    })->implode(', ');
                            @endphp
                            <tr>
                                <td>{{ $attendanceDate }}</td>
                                <td>{{ $dayName }}</td>
                                <td>Present</td>
                                <td>{{ $shiftLabel ?: '-' }}</td>
                                <td>{{ $clockIn }}</td>
                                <td>{{ $clockOut }}</td>
                                <td>{{ $formatAttendanceMinutes($breakMinutes) }}</td>
                                <td>{{ $formatAttendanceMinutes($regularMinutes) }}</td>
                                <td>{{ $formatAttendanceMinutes($overtimeMinutes) }}</td>
                                <td>{{ $formatAttendanceMinutes($payableMinutes) }}</td>
                            </tr>
                        @endforeach
                    @endif

                @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
    <div class="grand-total" style="margin-top:22px;">
        <table>
            <tr><td><strong>GRAND TOTAL DUE</strong></td><td class="text-right"><strong>${{ number_format((float) $invoice->total_amount, 2) }}</strong></td></tr>
        </table>
    </div>
</div>

</body>
</html>
