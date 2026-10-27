<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; margin: 0; padding: 0; }
        .invoice-box { width: 100%; color: #333; }
        .header { border-bottom: 2px solid #0b5b50; padding-bottom: 10px; margin-bottom: 20px; }
        .header img { width: 245px; max-width: 245px; height: auto; display: block; }
        .title { font-size: 22px; font-weight: bold; color: #0b5b50; }
        .flex { display: flex; justify-content: space-between; align-items: center; }
        .section-title { background: #0b5b50; color: #fff; padding: 6px; font-weight: bold; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table th, table td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        table th { background: #f2f2f2; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row td { font-weight: bold; }
        .blue-total { background: #0b5b50; color: #fff; font-weight: bold; }
        .account-details p { margin: 2px 0; }
        .note { font-size: 11px; color: #555; margin-top: 10px; }
        .footer { text-align: center; font-weight: bold; color: #0b5b50; margin-top: 20px; }
        .page-break { page-break-after: always; }
        .attendance-section { margin-bottom: 30px; }
    </style>
</head>
<body>
    <div class="invoice-box">

        {{-- Header --}}
        @php
            $clientTimezone = $clientTimezone ?? ($invoice->client->timezone ?: 'UTC');
            $carevmaLogoPath = public_path('images/CareVMA-full-logo.png');
            $carevmaLogoSrc = file_exists($carevmaLogoPath)
                ? 'data:image/png;base64,' . base64_encode(file_get_contents($carevmaLogoPath))
                : null;
        @endphp
        <div class="header flex">
            <div>
                @if($carevmaLogoSrc)
                    <img src="{{ $carevmaLogoSrc }}" alt="CareVMA">
                @else
                    <div style="font-size:28px;font-weight:700;color:#153a8a;">CARE <span style="color:#48cfdd;">VMA</span></div>
                @endif
            </div>
            <div style="text-align:right;">
                <h3 style="margin:0; color:#0b5b50;">INVOICE</h3>
            </div>
        </div>

        {{-- Invoice Info --}}
        <div class="flex">
            <div>
                <p><strong>INVOICE #:</strong> {{ $invoice->invoice_number }}</p>
                <p><strong>Date:</strong> {{ \Carbon\Carbon::parse($invoice->created_at)->setTimezone($clientTimezone)->format('d F Y') }}</p>
            </div>
            <div style="text-align:right;">
                <p><strong>FOR:</strong> Medical VA Service</p>
                <p><strong>BILL MONTH:</strong> {{ \Carbon\Carbon::parse($invoice->period_end)->format('F Y') }}</p>
            </div>
        </div>

        {{-- Bill To --}}
        <div class="section-title">BILL TO</div>
        <p><strong>{{ $invoice->client->name }}</strong></p>
        <p>{{ $invoice->client->address ?? '' }}</p>
        @if($invoice->client->email)
            <p>{{ $invoice->client->email }}</p>
        @endif

        {{-- Table --}}
        <div class="section-title">ITEM DESCRIPTION</div>
        <table>
            <thead>
                <tr>
                    <th>VA Name</th>
                    <th>Worked Time</th>
                    <th>Break Time</th>
                    <th>Work + Break</th>
                    <th>Rate/h</th>
                    <th>From</th>
                    <th>To</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->salaries as $salary)
                @php
                    $rate = isset($clientEmployeeRates[$salary->employee_id]) ? $clientEmployeeRates[$salary->employee_id]->per_hour_rate : 0;
                    $workedMinutes = max(0, (int) round(((float) $salary->total_hours) * 60));
                    $breakSeconds = (int) $salary->employee->attendances->sum(function ($attendance) {
                        return max(0, (int) ($attendance->break_taken ?? 0));
                    });
                    $breakMinutes = (int) round($breakSeconds / 60);
                    $workPlusBreakMinutes = $workedMinutes + $breakMinutes;
                    $formatMinutes = function (int $minutes): string {
                        $minutes = max(0, $minutes);
                        $hours = intdiv($minutes, 60);
                        $mins = $minutes % 60;
                        return $hours > 0 ? sprintf('%dh %02dm', $hours, $mins) : sprintf('%dm', $mins);
                    };
                @endphp
                <tr>
                    <td>{{ $salary->employee->first_name }} {{ $salary->employee->last_name }}</td>
                    <td>{{ $formatMinutes($workedMinutes) }}</td>
                    <td>{{ $formatMinutes($breakMinutes) }}</td>
                    <td><strong>{{ $formatMinutes($workPlusBreakMinutes) }}</strong></td>
                    <td>${{ number_format($rate, 2) }}</td>
                    <td>{{ \Carbon\Carbon::parse($salary->period_start)->format('d-M') }}</td>
                    <td>{{ \Carbon\Carbon::parse($salary->period_end)->format('d-M') }}</td>
                    <td class="text-right">${{ number_format($salary->salary_amount, 2) }}</td>
                </tr>
                @endforeach
                @php
                    $baseSubtotal = (float) $invoice->salaries->sum('salary_amount');
                @endphp
                <tr>
                    <td colspan="7" class="text-right"><strong>Attendance / Salary Subtotal</strong></td>
                    <td class="text-right">${{ number_format($baseSubtotal, 2) }}</td>
                </tr>
                <tr class="blue-total">
                    <td colspan="7" class="text-right"><strong>TOTAL COST</strong></td>
                    <td class="text-right"><strong>${{ number_format((float) $invoice->total_amount, 2) }}</strong></td>
                </tr>
            </tbody>
        </table>

        @if($invoice->adjustments->isNotEmpty())
            <div class="section-title" style="margin-top:18px;">INVOICE ADJUSTMENTS</div>
            <table>
                <thead>
                    <tr>
                        <th>VA / Applies To</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th class="text-right">Amount</th>
                    </tr>
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
                            <td class="text-right" style="font-weight:700;">{{ $isDeduction ? '-' : '+' }}${{ number_format((float) $adjustment->amount, 2) }}</td>
                        </tr>
                    @endforeach
                    @php
                        $adjustmentNet = $invoice->adjustments->sum(function ($item) {
                            return $item->direction === 'deduction' ? -((float) $item->amount) : (float) $item->amount;
                        });
                    @endphp
                    <tr>
                        <td colspan="4" class="text-right"><strong>Net adjustments</strong></td>
                        <td class="text-right"><strong>{{ $adjustmentNet < 0 ? '-' : '+' }}${{ number_format(abs($adjustmentNet), 2) }}</strong></td>
                    </tr>
                </tbody>
            </table>
        @endif

        {{-- Payment Info --}}
        <p style="margin-top:20px;">Please pay via Payoneer or to the account given below.</p>
        <div class="section-title">ACCOUNT DETAILS</div>
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

    {{-- Page Break --}}
    <div class="page-break"></div>

    {{-- Attendance Page --}}
    <div class="invoice-box">
        <h2 style="color:#0b5b50;">Attendance Records</h2>
        <p><strong>Period:</strong> {{ \Carbon\Carbon::parse($invoice->period_start)->format('d F Y') }} - {{ \Carbon\Carbon::parse($invoice->period_end)->format('d F Y') }}</p>
        <p><strong>Timezone:</strong> {{ $clientTimezone }} (Doctor / Client timezone)</p>

        @foreach($invoice->salaries as $salary)
            <div class="attendance-section">
                <h4 style="background: #f2f2f2; padding: 8px; margin-top: 20px;">
                    {{ $salary->employee->first_name }} {{ $salary->employee->last_name }}
                    (Total Worked: {{ (int) round(((float) $salary->total_hours) * 60) }} min)
                </h4>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Clock In</th>
                            <th>Clock Out</th>
                            <th>Worked Time</th>
                            <th>Break Time</th>
                            <th>Work + Break</th>
                            <th>Late (mins)</th>
                            <th>Overtime (mins)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($salary->employee->attendances as $attendance)
                            @php
                                // Invoice attendance is always rendered in the Doctor / Client timezone.
                                // The VA timezone is intentionally not used here.
                                $timezone = $clientTimezone;

                                $clockInLocal = $attendance->clock_in
                                    ? \Carbon\Carbon::parse($attendance->clock_in)->setTimezone($timezone)
                                    : null;
                                $clockOutLocal = $attendance->clock_out
                                    ? \Carbon\Carbon::parse($attendance->clock_out)->setTimezone($timezone)
                                    : null;

                                $attendanceDate = $clockInLocal
                                    ? $clockInLocal->format('d-M-Y')
                                    : \Carbon\Carbon::parse($attendance->date)->format('d-M-Y');
                                $clockIn = $clockInLocal ? $clockInLocal->format('h:i A') : '-';
                                $clockOut = $clockOutLocal ? $clockOutLocal->format('h:i A') : '-';
                                
                                // Use stored payable/regular minutes when available so breaks are
                                // reflected in the invoice. Fall back to displayed minute precision.
                                $workedHours = '-';
                                if ($attendance->total_minutes !== null) {
                                    $workedHours = max(0, (int) round($attendance->total_minutes)) . ' min';
                                } elseif ($attendance->clock_in && $attendance->clock_out) {
                                    $start = $clockInLocal->copy()->startOfMinute();
                                    $end = $clockOutLocal->copy()->startOfMinute();

                                    $minutes = max(0, (int) $start->diffInMinutes($end));
                                    $workedHours = $minutes . ' min';
                                }

                                $workedMinutesValue = max(0, (int) ($attendance->total_minutes ?? 0));
                                $breakMinutesValue = max(0, (int) round(((int) ($attendance->break_taken ?? 0)) / 60));
                                $workPlusBreakMinutes = $workedMinutesValue + $breakMinutesValue;

                                $formatAttendanceMinutes = function (int $minutes): string {
                                    $minutes = max(0, $minutes);
                                    $hours = intdiv($minutes, 60);
                                    $mins = $minutes % 60;
                                    return $hours > 0 ? sprintf('%dh %02dm', $hours, $mins) : sprintf('%dm', $mins);
                                };

                                $lateMinutes = (int) ($attendance->late_minutes ?? 0);
                                $overtimeMinutes = (int) ($attendance->approved_overtime_minutes ?? $attendance->overtime ?? 0);
                            @endphp
                            <tr>
                                <td>{{ $attendanceDate }}</td>
                                <td>{{ $clockIn }}</td>
                                <td>{{ $clockOut }}</td>
                                <td>{{ $workedHours }}</td>
                                <td>{{ $formatAttendanceMinutes($breakMinutesValue) }}</td>
                                <td><strong>{{ $formatAttendanceMinutes($workPlusBreakMinutes) }}</strong></td>
                                <td>{{ $lateMinutes > 0 ? $lateMinutes : '-' }}</td>
                                <td>{{ $overtimeMinutes > 0 ? $overtimeMinutes : '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center">No attendance records found for this period</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
</body>
</html>