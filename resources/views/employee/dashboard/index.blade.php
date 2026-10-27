@extends('employee.layouts.app')

@section('employee_content')
    <div class="container-xxl flex-grow-1 container-p-y">
        <div class="row d-flex justify-content-center mt-5 align-items-center mb-5">

            <div class="col-md-10">
                <div class="custom-card-body-2">
                    <div class="row align-items-start">

                        {{-- LEFT --}}
                        <div class="col-md-4">
                            <h5 class="custom-page-title mb-0 text-white">
                                Welcome, {{ $employee->first_name }}
                            </h5>

                            <div class="mt-2">
                                @if (!$shiftInfo)
                                    <p class="mb-2 text-white" style="font-size: 12px;">
                                        You have no shift today
                                    </p>
                                @else
                                    @if ($shiftInfo['status'] === 'upcoming')
                                        <p class="mb-2 text-white" style="font-size: 12px;">
                                            Your shift starts at
                                            <strong>{{ $shiftInfo['start_local']->format('h:i A') }}</strong>
                                            <span id="shiftCountdown"></span>
                                        </p>
                                    @elseif($shiftInfo['status'] === 'upcoming_ready')
                                        <p class="mb-2 text-white" style="font-size: 12px;">
                                            Your shift starts in <span id="shiftCountdown"></span>
                                            <br>
                                            <small>You can clock in now</small>
                                        </p>
                                    @elseif($shiftInfo['status'] === 'ongoing')
                                        <p class="mb-2 text-white" style="font-size: 12px;">
                                            Your shift is active. You can still clock in
                                            (scheduled end time: {{ $shiftInfo['end_local']->format('h:i A') }})
                                        </p>
                                    @elseif($shiftInfo['status'] === 'checked_in')
                                        <p class="mb-2 text-white" style="font-size: 12px;">
                                            You are currently checked in
                                            (shift ends at {{ $shiftInfo['end_local']->format('h:i A') }})
                                        </p>
                                    @elseif($shiftInfo['status'] === 'completed')
                                        <p class="mb-2 text-white" style="font-size: 12px;">
                                            You already completed your shift for today
                                        </p>
                                    @endif

                                    <p class="mb-1 text-white" style="font-size: 11px;">
                                        Shift Timezone: <strong>{{ $shiftInfo['slot_timezone'] }}</strong>
                                    </p>

                                    <p class="mb-0 text-white" style="font-size: 11px;">
                                        Shift Time:
                                        <strong>
                                            {{ $shiftInfo['start_local']->format('h:i A') }}
                                            -
                                            {{ $shiftInfo['end_local']->format('h:i A') }}
                                            @if ($shiftInfo['start_local']->toDateString() != $shiftInfo['end_local']->toDateString())
                                                (next day)
                                            @endif
                                        </strong>
                                    </p>
                                @endif

                                {{-- Attendance status --}}
                                @if ($attendanceToday && $attendanceToday->clock_in && !$attendanceToday->clock_out)
                                    <p class="mt-2 mb-0 text-white" style="font-size: 12px;">
                                        Your attendance is in progress
                                    </p>
                                @elseif($attendanceToday && $attendanceToday->clock_out)
                                    <p class="mt-2 mb-0 text-white" style="font-size: 12px;">
                                        You closed your shift
                                    </p>
                                @endif
                            </div>

                            @php
                                $hasActiveBreak =
                                    $attendanceToday &&
                                    $attendanceToday->breaks &&
                                    $attendanceToday->breaks->whereNull('end_time')->count() > 0;
                            @endphp

                            <div class="d-flex gap-3 justify-content-start mt-4 flex-wrap">

                                {{-- Clock In Button with ID for auto-show/hide --}}
                                <div id="clockInButtonContainer"
                                    style="{{ $canClockIn && (!$attendanceToday || !$attendanceToday->clock_in) ? 'display: block;' : 'display: none;' }}">
                                    <form method="POST" action="{{ route('attendance.clockIn') }}">
                                        @csrf
                                        <button type="submit" class="btn cstm-btn-link text-white">
                                            Clock In
                                        </button>
                                    </form>
                                </div>

                                {{-- Clock Out --}}
                                @if ($attendanceToday && $attendanceToday->clock_in && !$attendanceToday->clock_out)
                                    <form id="clockOutForm" method="POST" action="{{ route('attendance.clockOut') }}">
                                        @csrf
                                        <button type="button" class="btn cstm-btn-link text-white" data-bs-toggle="modal"
                                            data-bs-target="#clockOutModal">
                                            Clock Out
                                        </button>
                                    </form>
                                @endif

                                {{-- Break --}}
                                @if ($attendanceToday && $attendanceToday->clock_in && !$attendanceToday->clock_out)
                                    @if (!$hasActiveBreak)
                                        <button type="button" class="btn options-btn" style="color: white;"
                                            data-bs-toggle="modal" data-bs-target="#breakModal">
                                            Break
                                        </button>
                                    @else
                                        <form method="POST" action="{{ route('attendance.endBreak') }}">
                                            @csrf
                                            <button type="submit" class="btn options-btn" style="color: white;">
                                                End Break
                                            </button>
                                        </form>
                                    @endif
                                @endif
                            </div>

                            {{-- Worked summary --}}
                            @if ($attendanceToday && $attendanceToday->clock_out)
                                <div class="mt-3">
                                    <p class="worked-today mb-1 text-white">
                                        ⏱ Worked Today:
                                        {{ floor(($attendanceToday->total_minutes ?? 0) / 60) }}h
                                        {{ ($attendanceToday->total_minutes ?? 0) % 60 }}m
                                    </p>

                                    @if (($attendanceToday->overtime ?? 0) > 0)
                                        <p class="text-danger mb-0">
                                            🔥 Overtime:
                                            {{ floor(($attendanceToday->overtime ?? 0) / 60) }}h
                                            {{ ($attendanceToday->overtime ?? 0) % 60 }}m
                                        </p>
                                    @endif
                                </div>
                            @endif
                        </div>

                        {{-- CENTER --}}
                        <div class="col-md-4">
                            @if ($attendanceToday && $attendanceToday->clock_in && !$attendanceToday->clock_out)
                                <div class="work-div">
                                    <h2 class="text-white" id="workTimer">00:00:00</h2>
                                </div>

                                <div id="breakSection" class="mt-4" style="display: none;">
                                    <h2 class="text-danger mb-0" style="font-size: 12px;" id="breakTimer">00:00:00</h2>
                                </div>
                            @endif
                        </div>

                        {{-- RIGHT --}}
                        <div class="col-md-4 text-end text-sm-left">
                            <div class="pb-0 px-0 px-md-4">
                                @if (auth()->user()->employee->gender === 'male')
                                    <img src="/assets/img/illustrations/card-advance-sale.png" height="140"
                                        alt="work" />
                                @else
                                    <img src="/assets/img/illustrations/girl-with-laptop.png" height="140"
                                        alt="work" />
                                @endif
                            </div>
                        </div>

                    </div>
                </div>
            </div>

        </div>

        {{-- Break Modal --}}
        <div class="modal fade" id="breakModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('attendance.startBreak') }}" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Break Notes</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <textarea name="notes" class="form-control" placeholder="Reason for break..." required></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-warning">Start Break</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Clock Out Modal --}}
        <div class="modal fade" id="clockOutModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm Clock Out</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        Are you sure you want to clock out?
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            No
                        </button>
                        <button type="button" class="btn btn-danger"
                            onclick="document.getElementById('clockOutForm').submit();">
                            Yes, Clock Out
                        </button>
                    </div>
                </div>
            </div>
        </div>


        <script>
            document.addEventListener("DOMContentLoaded", function() {
                const clockIn = @json($attendanceToday?->clock_in);
                const clockOut = @json($attendanceToday?->clock_out);
                const activeBreak = @json($attendanceToday?->breaks?->whereNull('end_time')->first());
                const allBreaks = @json($attendanceToday?->breaks?->values());

                // Get shift timestamps from PHP
                const shiftStartTimestamp = @json($shiftStartTimestamp);
                const shiftEndTimestamp = @json($shiftEndTimestamp);
                const hasClockedIn = @json($attendanceToday && $attendanceToday->clock_in);
                const hasClockedOut = @json($attendanceToday && $attendanceToday->clock_out);

                const workTimerEl = document.getElementById("workTimer");
                const breakTimerEl = document.getElementById("breakTimer");
                const breakSection = document.getElementById("breakSection");
                const clockInButtonContainer = document.getElementById("clockInButtonContainer");

                let workInterval = null;
                let breakInterval = null;

                function formatTime(seconds) {
                    seconds = Math.max(0, Math.floor(seconds));
                    const h = String(Math.floor(seconds / 3600)).padStart(2, "0");
                    const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, "0");
                    const s = String(seconds % 60).padStart(2, "0");
                    return `${h}:${m}:${s}`;
                }

                function calculateBreakSeconds(breaks) {
                    if (!Array.isArray(breaks)) return 0;
                    let total = 0;
                    breaks.forEach(b => {
                        if (b && b.start_time && b.end_time) {
                            total += (new Date(b.end_time) - new Date(b.start_time)) / 1000;
                        }
                    });
                    return total;
                }

                function startWorkTimer() {
                    if (!clockIn || clockOut || !workTimerEl) return;

                    clearInterval(workInterval);

                    workInterval = setInterval(() => {
                        const now = new Date();
                        let diff = (now - new Date(clockIn)) / 1000;
                        diff -= calculateBreakSeconds(allBreaks);

                        if (activeBreak && activeBreak.start_time) {
                            diff -= (now - new Date(activeBreak.start_time)) / 1000;
                        }

                        if (diff >= 0) {
                            workTimerEl.textContent = formatTime(diff);
                        }
                    }, 1000);
                }

                function startBreakTimer() {
                    if (!activeBreak || !activeBreak.start_time || !breakTimerEl) return;

                    if (breakSection) {
                        breakSection.style.display = "block";
                    }

                    clearInterval(breakInterval);

                    breakInterval = setInterval(() => {
                        const diff = (new Date() - new Date(activeBreak.start_time)) / 1000;
                        if (diff >= 0) {
                            breakTimerEl.textContent = formatTime(diff);
                        }
                    }, 1000);
                }

                // Function to check and show/hide clock-in button automatically
                function checkAndUpdateClockInButton() {
                    if (!shiftStartTimestamp || !shiftEndTimestamp) return;
                    if (hasClockedIn || hasClockedOut) return;

                    const now = Math.floor(Date.now() / 1000);
                    const secondsUntilShift = shiftStartTimestamp - now;

                    // Check if shift end time has passed
                    if (now > shiftEndTimestamp) {
                        // Shift is over, hide button
                        if (clockInButtonContainer && clockInButtonContainer.style.display !== 'none') {
                            clockInButtonContainer.style.display = 'none';

                            // Update status text to show shift completed
                            const statusParagraph = document.querySelector('.mt-2 p');
                            if (statusParagraph && statusParagraph.textContent.includes('Your shift')) {
                                statusParagraph.innerHTML = 'You already completed your shift for today';
                            }
                        }
                        return;
                    }

                    // Show button when 10 minutes or less remaining, and shift not started yet
                    if (secondsUntilShift <= 600 && secondsUntilShift > 0) {
                        if (clockInButtonContainer && clockInButtonContainer.style.display === 'none') {
                            clockInButtonContainer.style.display = 'block';
                        }
                    }
                    // Hide button if more than 10 minutes remaining
                    else if (secondsUntilShift > 600) {
                        if (clockInButtonContainer && clockInButtonContainer.style.display === 'block') {
                            clockInButtonContainer.style.display = 'none';
                        }
                    }
                    // If shift is ongoing (negative secondsUntilShift)
                    else if (secondsUntilShift <= 0 && now <= shiftEndTimestamp) {
                        if (clockInButtonContainer && clockInButtonContainer.style.display === 'none') {
                            clockInButtonContainer.style.display = 'block';
                        }
                    }
                }

                // Start the timer - keep the original timer functionality exactly as before
                if (clockIn && !clockOut) {
                    startWorkTimer();
                    if (activeBreak) {
                        startBreakTimer();
                    }
                }

                // Countdown for shift start - keep original functionality
                @if (
                    $shiftInfo &&
                        ($shiftInfo['status'] === 'upcoming' || $shiftInfo['status'] === 'upcoming_ready') &&
                        $shiftInfo['seconds_until'] !== null)
                    let seconds = {{ $shiftInfo['seconds_until'] }};
                    const shiftCountdownEl = document.getElementById('shiftCountdown');

                    function formatCountdown(sec) {
                        sec = Math.max(0, Math.floor(sec));
                        const h = Math.floor(sec / 3600);
                        const m = Math.floor((sec % 3600) / 60);
                        const s = Math.floor(sec % 60);
                        if (h > 0) {
                            return ` (${h}h ${m}m ${s}s)`;
                        } else if (m > 0) {
                            return ` (${m}m ${s}s)`;
                        } else {
                            return ` (${s}s)`;
                        }
                    }

                    function tickShift() {
                        if (!shiftCountdownEl) return;

                        // Check and update clock-in button visibility
                        checkAndUpdateClockInButton();

                        if (seconds <= 0) {
                            shiftCountdownEl.textContent = " (shift starting now)";
                            // Show clock-in button if not already shown
                            if (clockInButtonContainer && clockInButtonContainer.style.display === 'none') {
                                clockInButtonContainer.style.display = 'block';
                            }
                            // Don't reload - keep the timer running
                            return;
                        }

                        shiftCountdownEl.textContent = formatCountdown(seconds);
                        seconds--;
                        setTimeout(tickShift, 1000);
                    }

                    tickShift();
                @elseif ($shiftStartTimestamp)
                    // If no countdown initially but we have shift start time, start checking
                    setInterval(checkAndUpdateClockInButton, 1000);
                @endif

                // Also check every second for clock-in button visibility and shift completion
                setInterval(checkAndUpdateClockInButton, 1000);
            });
        </script>
    </div>
@endsection