@extends('admin.layouts.app')
@section('admin_content')


    <div class="container-xxl flex-grow-1 container-p-y">

        <div class='row d-flex justify-content-center mt-5 align-items-center'>
            <div class="col-md-5 custom-title-col">
                <h4 class='mb-0 custom-page-title'>Employees</h4>
                <p>View, add, edit and delete employees details.</p>
            </div>
            <div class="col-md-5 text-end d-flex align-items-center justify-content-end gap-2">
                <a href="{{ route('departments.index') }}" class="cstm-btn-link-2 text-white btn">Options</a>
                <div class="btn-group">
                    <button type="button" class="options-btn dropdown-toggle" data-bs-toggle="dropdown"
                        aria-expanded="false">
                        Export
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><strong style="font-size:13px;font-weight:700;color:black;">Export</strong></li>
                        <div id="exportDropdown"></div>
                    </ul>
                </div>
                <a href="{{ route('add.employee') }}" class='btn cstm-btn-link text-white'>Add</a>
            </div>
        </div>

        <div class="col-md-10 mt-5">
            @if(session('success'))
                <div class="alert alert-solid-success d-flex align-items-center" role="alert">
                    <span class="alert-icon rounded">
                        <i class="icon-base ti tabler-check icon-md"></i>
                    </span>
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-solid-danger d-flex align-items-center" role="alert">
                    <span class="alert-icon rounded">
                        <i class="icon-base ti tabler-ban icon-md"></i>
                    </span>
                    {{ implode(' | ', $errors->all()) }}
                </div>
            @endif
        </div>

        <div class="row d-flex justify-content-center mt-5 align-items-center">
            <div class="col-md-10">
                <div class="custom-card-body">
                    <table class="table table-bordered" id="employees-table">
                        <thead>
                            <tr>
                                <th>Emp #</th>
                                <th>Full Name</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Shift</th>
                                <th>Timezone</th>
                                <th>Salary</th>
                                <th>Joining</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div class="modal fade" id="deleteEmployeeModal" tabindex="-1" aria-labelledby="deleteEmployeeModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header p-3 bg-danger">
                        <h5 class="modal-title text-white" id="deleteEmployeeModalLabel">Confirm Delete</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        Are you sure you want to delete <strong id="employeeName"></strong>?
                        <input type="hidden" id="employeeId">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirmDelete">Yes, Delete</button>
                    </div>
                </div>
            </div>
        </div>


        <script>
            $(function () {
                var table = $('#employees-table').DataTable({
                    processing: true,
                    serverSide: true,
                    dom: 'Bfrtip',
                    buttons: [
                        { extend: 'copyHtml5', text: '<i class="ti tabler-copy me-1"></i> Copy' },
                        { extend: 'excelHtml5', text: '<i class="ti tabler-file-spreadsheet me-1"></i> Excel' },
                        { extend: 'csvHtml5', text: '<i class="ti tabler-file me-1"></i> CSV' },
                        { extend: 'pdfHtml5', text: '<i class="ti tabler-file-text me-1"></i> PDF' },
                        { extend: 'print', text: '<i class="ti tabler-printer me-1"></i> Print' }
                    ],
                    ajax: {
                        url: '{{ route("all.employees") }}',
                        data: function (d) {
                            d.status = $('#status-filter').val();
                        }
                    },
                    columns: [
                        { data: 'employee_code', name: 'employee_code' },
                        { data: 'employee_info', name: 'employee_info' },
                        { data: 'department', name: 'department' },
                        { data: 'status', name: 'status' },
                        { data: 'shift_type', name: 'shift_type' },
                        { data: 'timezone', name: 'timezone' },
                        { data: 'salary', name: 'salary' },
                        { data: 'date_of_joining', name: 'date_of_joining' },
                        { data: 'actions', name: 'actions', orderable: false, searchable: false },
                    ]
                });

                table.buttons().container().appendTo('#exportDropdown');

                // Redraw on filter change
                $('#status-filter').change(function () {
                    table.draw();
                });

            });

            $(document).ready(function () {
                let deleteId = null;

                // Open modal on delete button click
                $(document).on("click", ".delete-employee", function () {
                    deleteId = $(this).data("id");
                    let name = $(this).data("name");
                    $("#employeeName").text(name);
                    $("#deleteEmployeeModal").modal("show");
                });

                // Confirm delete
                $("#confirmDelete").click(function () {
                    if (deleteId) {
                        $.ajax({
                            url: "/admin/employees/" + deleteId, // matches your route
                            type: "DELETE",
                            data: { _token: "{{ csrf_token() }}" },
                            success: function (res) {
                                $("#deleteEmployeeModal").modal("hide");

                                if (res.success) {
                                    $('#employees-table').DataTable().ajax.reload();
                                } else {
                                    alert('something went wrong');
                                }
                            },
                            error: function (err) {
                                $("#deleteEmployeeModal").modal("hide");
                                toastr.error("Failed to delete employee");
                                console.error(err.responseJSON);
                            }
                        });
                    }
                });
            });


        </script>


@endsection