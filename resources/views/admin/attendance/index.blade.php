@extends('admin.layouts.app')

@section('admin_content')
    <div class="container-xxl flex-grow-1 container-p-y py-5">
        <div class='row d-flex justify-content-center mt-5 align-items-center'>
            <div class="col-md-5 custom-title-col">
                <h4 class='mb-0 custom-page-title'>Attendance</h4>
                <p>View, add, edit and delete all attendance details.</p>
            </div>
            <div class="col-md-5 text-end">
                <a href="{{ route('admin.attendance.requests') }}" class='btn cstm-btn-link text-white'>Change Requests</a>
            </div>
        </div>


        <div class="row d-flex justify-content-center mt-5 align-items-center">
            <div class="col-md-10">
                <div class="custom-card-body">
                    <!-- Filter Form -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <form id="attendanceFilterForm" class="row g-3">
                                <div class="col-md-3">
                                    <input type="text" name="employee_name" class="form-control"
                                        placeholder="Employee Name">
                                </div>
                                <div class="col-md-3">
                                    <input type="date" name="from_date" class="form-control" placeholder="From Date">
                                </div>
                                <div class="col-md-3">
                                    <input type="date" name="to_date" class="form-control" placeholder="To Date">
                                </div>
                                <div class="col-md-3 d-flex">
                                    <button type="submit" class="btn btn-primary me-2">Filter</button>
                                    <button type="button" id="resetFilter" class="btn btn-light">Reset</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <table id="attendanceTable" class="table table-bordered mt-5">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Employee</th>
                                <th>Date</th>
                                <th>Clock In</th>
                                <th>Clock Out</th>
                                <th>Breaks Time</th>
                                <th>Worked Hours</th>
                                <th>Over Time</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(function() {
            let table = $('#attendanceTable').DataTable({
                processing: true,
                serverSide: true,
                searching: false,
                ajax: {
                    url: "{{ route('admin.attendance') }}",
                    data: function(d) {
                        d.employee_name = $('input[name=employee_name]').val();
                        d.from_date = $('input[name=from_date]').val();
                        d.to_date = $('input[name=to_date]').val();
                    }
                },
                columns: [{
                        data: 'DT_RowIndex',
                        name: 'DT_RowIndex',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'name',
                        name: 'name',
                        searchable: false
                    },
                    {
                        data: 'date',
                        name: 'date',
                        searchable: false
                    },
                    {
                        data: 'clock_in',
                        name: 'clock_in',
                        searchable: false
                    },
                    {
                        data: 'clock_out',
                        name: 'clock_out',
                        searchable: false
                    },
                    {
                        data: 'breaks',
                        name: 'breaks',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'worked_hours',
                        name: 'worked_hours',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'overtime',
                        name: 'overtime',
                        orderable: false,
                        searchable: false
                    },
                ],
                order: [
                    [2, 'desc']
                ]
            });

            // Filter form submit
            $('#attendanceFilterForm').on('submit', function(e) {
                e.preventDefault();
                table.draw();
            });

            // Reset Filter button
            $('#resetFilter').on('click', function() {
                $('#attendanceFilterForm')[0].reset();
                table.draw();
            });
        });
    </script>
@endsection