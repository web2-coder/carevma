@extends('employee.layouts.app')
@section('employee_content')

<div class="container-xxl flex-grow-1 container-p-y">
   <div class="card text-center">
    <div class="card-body">
        <h4>Employee Dashboard</h4>
                    @if ($errors->any())
                        <div class="alert alert-danger">
                            <ul>
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

        <!-- Buttons -->
        @if(!$attendanceToday || !$attendanceToday->clock_in)
            <form method="POST" action="{{ route('attendance.clockIn') }}">
                @csrf
                <button class="btn btn-success">Clock In</button>
            </form>
        @elseif(!$attendanceToday->clock_out)
            <form id="clockOutForm" method="POST" action="{{ route('attendance.clockOut') }}">
                @csrf
                <button type="button" class="btn btn-danger mt-3" data-bs-toggle="modal" data-bs-target="#clockOutModal">
                    Clock Out
                </button>
            </form>
        @else
            <p>✅ You have clocked in and out today.</p>
        @endif

        <!-- Circular Progress -->
        <div class="mt-4 d-flex justify-content-center">
            <svg id="progressCircle" width="180" height="180" viewBox="0 0 120 120">
                <!-- Background Circle -->
                <circle cx="60" cy="60" r="54" stroke="#e6e6e6" stroke-width="12" fill="none"/>
                
                <!-- Progress Circle -->
                <circle id="progressPath" cx="60" cy="60" r="54" 
                        stroke="#0d6efd" stroke-width="12" fill="none" 
                        stroke-linecap="round"
                        stroke-dasharray="339.292" 
                        stroke-dashoffset="339.292" 
                        transform="rotate(-90 60 60)"/>
            </svg>
        </div>

       

    </div>
   </div>

   <!-- Break Buttons -->
@if($attendanceToday && $attendanceToday->clock_in && !$attendanceToday->clock_out)
    @if(!$attendanceToday->breaks->whereNull('end_time')->count())
        <!-- Start Break -->
        <button class="btn btn-warning mt-3" data-bs-toggle="modal" data-bs-target="#breakModal">Break</button>
    @else
        <!-- End Break -->
        <form method="POST" action="{{ route('attendance.endBreak') }}">
            @csrf
            <button class="btn btn-primary mt-3">End Break</button>
        </form>
    @endif
@endif

<!-- Break Modal -->
<div class="modal fade" id="breakModal" tabindex="-1">
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
</div>


<!-- Clockout Modal -->
<div class="modal fade" id="clockOutModal" tabindex="-1" aria-labelledby="clockOutModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="clockOutModalLabel">Confirm Clock Out</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to clock out?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No</button>
        <button type="button" class="btn btn-danger" onclick="document.getElementById('clockOutForm').submit();">Yes, Clock Out</button>
      </div>
    </div>
  </div>
</div>


<script>
document.addEventListener("DOMContentLoaded", function() {
    let clockIn = @json($attendanceToday?->clock_in);
    let clockOut = @json($attendanceToday?->clock_out);
    let breakLimit = @json($attendanceToday?->break_limit ?? 0); 
    let onBreak = @json($attendanceToday?->breaks->whereNull('end_time')->count() > 0);

    const path = document.getElementById("progressPath");
    const circleLength = 2 * Math.PI * 54;
    path.setAttribute("stroke-dasharray", circleLength);

    let timerInterval, breakInterval;

    function updateProgress(diffSeconds) {
        let shiftSeconds = 5 * 60; // for testing: 5 minutes
        let percent = Math.min((diffSeconds / shiftSeconds) * 100, 100);
        let offset = circleLength - (circleLength * percent / 100);
        path.setAttribute("stroke-dashoffset", offset);
    }

    function startWorkTimer(startTime) {
        clearInterval(timerInterval);
        timerInterval = setInterval(() => {
            let now = new Date();
            let diffSeconds = Math.floor((now - startTime) / 1000);
            updateProgress(diffSeconds);
        }, 1000);
    }

    function startBreakTimer(limitTime) {
        clearInterval(breakInterval);

        let parts = String(limitTime).split(":");
        let hours   = parseInt(parts[0] ?? 0, 10);
        let minutes = parseInt(parts[1] ?? 0, 10);
        let seconds = parseInt(parts[2] ?? 0, 10);

        let remaining = (hours * 3600) + (minutes * 60) + seconds;

        breakInterval = setInterval(() => {
            if (remaining <= 0) {
                clearInterval(breakInterval);
                alert("⚠️ Your break time is over, please clock in again.");
                return;
            }

            remaining--;

            if (remaining === 0) {
                alert("⚠️ Your break time is over, please end break to clock.");
            }
        }, 1000);
    }

    // ---- Init ----
    if (clockIn && !clockOut && !onBreak) {
        let startTime = new Date(clockIn);
        startWorkTimer(startTime);
    }

    if (onBreak && breakLimit) {
        startBreakTimer(breakLimit); 
    }
});
</script>


@endsection