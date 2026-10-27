@extends('doctor.layouts.app')

@section('doctor_content')

<div class="container-xxl flex-grow-1 container-p-y py-5">

    <!-- HEADER -->
    <div class='row d-flex text-center justify-content-center mt-5 align-items-center'>
        <div class="col-md-12">
            <h4 class='mb-0 custom-page-title'>{{ $employee->first_name }} {{ $employee->last_name }} - Attendance</h4>
            <p>View all attendance details of this employee.</p>
        </div>
    </div>

    <!-- FILTER SECTION -->
    <div class="row d-flex justify-content-center mt-4">
        <div class="col-md-10">
            <div class="d-flex gap-2 mb-3">
                <input type="date" id="fromDate" class="form-control" placeholder="From Date">
                <input type="date" id="toDate" class="form-control" placeholder="To Date">
                <button id="filterBtn" class="btn btn-primary">Filter</button>
                <button id="resetBtn" class="btn btn-secondary">Reset</button>
            </div>
        </div>
    </div>

    <!-- TABLE SECTION -->
    <div class='row d-flex justify-content-center mt-3 align-items-center'>
        <div class='col-md-10'>
            <div class="custom-card-body">
                <table id="attendanceTable" class="table table-bordered">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Clock In</th>
                            <th>Clock Out</th>
                            <th>Break Taken</th>
                            <th>Total Minutes</th>
                            <th>Over Time</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
$(function () {

    function loadData(from = '', to = '') {
        $('#attendanceTable').DataTable({
            processing: true,
            serverSide: true,
            destroy: true, // important to reinitialize on filter
            ajax: {
                url: "{{ route('doctor.employee.attendance.data', $employee->id) }}",
                data: {
                    from_date: from,
                    to_date: to
                }
            },
            columns: [
                { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false },
                { data: 'date', name: 'date' },
                { data: 'clock_in', name: 'clock_in' },
                { data: 'clock_out', name: 'clock_out' },
                { data: 'break_taken', name: 'break_taken' },
                { data: 'total_minutes', name: 'total_minutes' },
                { data: 'overtime', name: 'overtime' },
            ],
            order: [[1, 'desc']]
        });
    }

    // Initial load
    loadData();

    // Filter button click
    $('#filterBtn').on('click', function() {
        let from = $('#fromDate').val();
        let to = $('#toDate').val();
        loadData(from, to);
    });

    // Reset button
    $('#resetBtn').on('click', function() {
        $('#fromDate').val('');
        $('#toDate').val('');
        loadData();
    });

});
</script>

@endsection
