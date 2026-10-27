@extends('employee.layouts.app')
@section('employee_content')

<div class="container-xxl flex-grow-1 container-p-y">
    <h4 class="mb-4">My Attendance</h4>
   
    <div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-auto">
            <label for="from_date" class="form-label">From Date:</label>
            <input type="date" id="from_date" class="form-control mb-2">
        </div>
        <div class="col-auto">
            <label for="to_date" class="form-label">To Date:</label>
            <input type="date" id="to_date" class="form-control mb-2">
        </div>
        <div class="col-auto align-self-end">
            <button id="resetBtn" class="btn btn-outline-secondary mb-2">
                <i class="bi bi-arrow-clockwise"></i> Reset
            </button>
        </div>
    </div>
</div>
<div id="successMessage" class="alert alert-success d-none"></div>
<div id="errorMessage" class="alert alert-danger d-none"></div>


          
    
    <div class="card">
        <div class="card-body">
            <table id="attendanceTable" class="table table-bordered">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Clock In</th>
                        <th>Clock Out</th>
                        <th>Breaks</th>
                        <!-- <th>Breaks Taken Time</th> -->
                        <th>Worked Hours</th>
                        <th>Over Time</th>
                        <th>Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<!-- Request Change Modal -->
<div class="modal fade" id="requestChangeModal" tabindex="-1" aria-labelledby="requestChangeModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="requestChangeForm">
      @csrf
      <input type="hidden" name="attendance_id" id="attendance_id">

      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="requestChangeModalLabel">Request Attendance Change</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        
        <div class="modal-body">
          <div class="mb-3">
            <label>Date</label>
            <input type="text" class="form-control" name="date" id="date" readonly>
          </div>
          <div class="mb-3">
            <label>Clock In</label>
            <input type="time" class="form-control" name="clock_in" id="clock_in" required>
          </div>
          <div class="mb-3">
            <label>Clock Out</label>
            <input type="time" class="form-control" name="clock_out" id="clock_out" required>
          </div>
          <div class="mb-3">
            <label>Reason</label>
            <textarea class="form-control" name="reason" required></textarea>
          </div>
        </div>
        
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Submit Request</button>
        </div>
      </div>
    </form>
  </div>
</div>


<script>

$(document).ready(function () {
    let table = $('#attendanceTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: "{{ route('my.attendance') }}",
            data: function (d) {
                d.from_date = $('#from_date').val();
                d.to_date   = $('#to_date').val();
            }
        },
        columns: [
            { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false },
            { data: 'date', name: 'date' },
            { data: 'clock_in', name: 'clock_in' },
            { data: 'clock_out', name: 'clock_out' },
            { data: 'breaks', name: 'breaks', orderable: false, searchable: false },
            // { data: 'break_taken', name: 'break_taken', orderable: false, searchable: false },
            { data: 'worked_hours', name: 'worked_hours', orderable: false, searchable: false },
            { data: 'overtime', name: 'overtime', orderable: false, searchable: false },
            { data: 'actions', name: 'actions', orderable: false, searchable: false },
        ],
        order: [[2, 'desc']]
    });

    // 🔍 Filter Button
    $('#from_date').on('change',function () {
        table.draw();
    });
    $('#to_date').on('change',function () {
        table.draw();
    });

    // 🔄 Reset Button
    $('#resetBtn').click(function () {
        $('#from_date').val('');
        $('#to_date').val('');
        table.draw();
    });



    $(document).on("click", ".request-change", function() {
    let id = $(this).data("id");
    let date = $(this).data("date");
    let clockIn = $(this).data("clock_in");
    let clockOut = $(this).data("clock_out");

    $("#attendance_id").val(id);
    $("#date").val(date);
    $("#clock_in").val(clockIn ? clockIn : "");
    $("#clock_out").val(clockOut ? clockOut : "");

    $("#requestChangeModal").modal("show");
});

$("#requestChangeForm").on("submit", function(e) {
    e.preventDefault();

    $.ajax({
        url: "{{ route('attendance.requestChange') }}",
        method: "POST",
        data: $(this).serialize(),
        success: function(response) {
            // Hide modal
            $("#requestChangeModal").modal("hide");

            // Show success message
            $("#successMessage")
                .removeClass("d-none")
                .text(response.message);

            // Auto-hide message after 5 sec
            setTimeout(function() {
                $("#successMessage").addClass("d-none");
            }, 5000);
        },
        error: function(xhr) {
            $("#requestChangeModal").modal("hide");

            let errorMsg = "Something went wrong!"; // fallback message

            if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMsg = xhr.responseJSON.message; // Laravel usually returns this
            } else if (xhr.responseText) {
                errorMsg = xhr.responseText; // raw response
            }

            $("#errorMessage")
                .removeClass("d-none")
                .text(errorMsg);


            setTimeout(function() {
                $("#errorMessage").addClass("d-none");
            }, 5000);
        }
    });
});


});
</script>

@endsection