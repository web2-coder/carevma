@extends('admin.layouts.app')

@section('admin_content')
<div class="container-xxl flex-grow-1 container-p-y">
        <div class='row d-flex justify-content-center mt-5 align-items-center'>
            <div class="col-md-5 custom-title-col">
                <h4 class='mb-0 custom-page-title'>Attendance Change Requests</h4>
                <p>View, add, edit and delete all attendance request details.</p>
            </div>
            <div class="col-md-5 text-end">
                <a href="{{ route('admin.attendance') }}" class='btn cstm-btn-link text-white'>Attendance Records</a>
            </div>
        </div>
       <div class="row d-flex justify-content-center mt-5 align-items-center">
        <div class="col-md-10">
            <div class="custom-card-body">
                <table id="attendanceTable" class="table table-bordered">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Employee</th>
                            <th>Date</th>
                            <th>Requested Clock In</th>
                            <th>Requested Clock Out</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
       </div>

</div>


<!-- View Attendance Request Modal -->
<div class="modal fade" id="viewRequestModal" tabindex="-1" aria-labelledby="viewRequestLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      
      <div class="modal-header">
        <h5 class="modal-title" id="viewRequestLabel">Attendance Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <p><strong>Date:</strong> <span id="reqDate"></span></p>
        <p><strong>Old Clock In:</strong> <span id="oldIn"></span></p>
        <p><strong>Old Clock Out:</strong> <span id="oldOut"></span></p>
        <p><strong>Requested Clock In:</strong> <span id="requestedIn"></span></p>
        <p><strong>Requested Clock Out:</strong> <span id="requestedOut"></span></p>
        <p id="reasontext"></p>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-danger" id="rejectBtn">Reject</button>
        <button type="button" class="btn btn-success" id="approveBtn">Approve</button>
      </div>

    </div>
  </div>
</div>


<script>

$(function () {
    let table = $('#attendanceTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: "{{ route('admin.attendance.requests') }}",
        columns: [
            { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false },
            { data: 'employee', name: 'employee' },
            { data: 'date', name: 'date' },
            { data: 'requested_clock_in', name: 'requested_clock_in' },
            { data: 'requested_clock_out', name: 'requested_clock_out' },
            { data: 'status', name: 'status', orderable: false, searchable: false },
            { data: 'action', name: 'action', orderable: false, searchable: false },
        ]
    });

    // View button click
    $(document).on('click', '.viewRequest', function() {
        let id = $(this).data('id');
        $('#reqDate').text($(this).data('date'));
        $('#oldIn').text($(this).data('oldin'));
        $('#oldOut').text($(this).data('oldout'));
        $('#requestedIn').text($(this).data('requestedin'));
        $('#requestedOut').text($(this).data('requestedout'));

        // Store request ID in modal for later approve/reject
        $('#approveBtn').data('id', id);
        $('#rejectBtn').data('id', id);

        $('#viewRequestModal').modal('show');
    });

    // Approve
    $(document).on('click', '#approveBtn', function() {
        let id = $(this).data('id');
        $.ajax({
            url: "{{ route('approve.attendance.request') }}",
            type: "POST",
            data: {
                _token: "{{ csrf_token() }}",
                id:id
            },
            success: function(res) {
                $('#viewRequestModal').modal('hide');
                table.ajax.reload();
            }
        });
    });

    // // Reject
    $(document).on('click', '#rejectBtn', function() {
        let id = $(this).data('id');
        $.ajax({
            url: "{{ route('reject.attendance.request') }}",
            type: "POST",
            data: {
                _token: "{{ csrf_token() }}",
                id:id
            },
            success: function(res) {
                $('#viewRequestModal').modal('hide');
                table.ajax.reload();
            }
        });
    });
});


</script>

@endsection