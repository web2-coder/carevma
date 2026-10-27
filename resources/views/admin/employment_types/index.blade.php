@extends('admin.layouts.app')
@section('admin_content')

<div class="container-xxl flex-grow-1 container-p-y">

        <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>
            <div class="col-md-5 custom-title-col">
                <h4 class='mb-0 custom-page-title'>Employee Options</h4>
                <p>Add, edit and delete employee option details.</p>
            </div>
            <div class="col-md-5 d-flex justify-content-end gap-2">
                <a href="{{ route('all.employees') }}" class='btn cstm-btn-link-2 text-white'>Employees</a>
                <button type="button" data-bs-toggle="modal" data-bs-target="#addEmployeeTypeModal" class="cstm-btn-link text-white">Add Employee Type</button>
            </div>

            <div class="col-md-10 mt-5" id="edit-alert" style="display: none;">
                    <div class="alert alert-solid-success d-flex align-items-center" role="alert">
                        <span class="alert-icon rounded">
                            <i class="icon-base ti tabler-check icon-md"></i>
                        </span>
                        <span class="edit-msg"></span>
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
        </div>

    <div class="row d-flex justify-content-center mt-5 align-items-center mb-5">
        <div class="col-md-10">
                <div class="nav-align-left">
                    @include('admin.layouts.employee_options_layouts')
                    <div class="custom-card-body w-100">
                    <table id="employeetypesTable" class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Employee Type</th>
                                <th>Employees</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                    </table>
                    </div>
                </div>
        </div>
    </div>
</div>


<!-- Add Modal -->
<div class="modal fade" id="addEmployeeTypeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
        <form action="{{ route('employment-types.store') }}" method="POST">
            <div class="modal-header">
                <h5 class="custom-page-title">Add Employee Type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            @csrf
            <div class="modal-body">
                <div class="mb-3">
                    <label>Name</label>
                    <input type="text" name="name" class="form-control" required>
                    @error('name') <small class="text-danger">{{ $message }}</small> @enderror
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn cstm-btn-link-2" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn cstm-btn-link text-white">Save</button>
            </div>
        </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editEmployeeTypeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="editEmployeeTypeForm" method="POST">
        @csrf
        @method('PUT')
        <input type="hidden" name="id" id="employee_type_id">

        <div class="modal-header">
          <h5 class="modal-title">Edit Employee Type</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="mb-3">
            <label>Role Name</label>
            <input type="text" name="name" id="employee_type_name" class="form-control" required>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
      </form>
    </div>
  </div>
</div>


<script>
    $(function() {

        var table = $('#employeetypesTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: "{{ route('employment-types.index') }}",
        columns: [
            { data: 'id', name: 'id' },
            { data: 'name', name: 'name' },
            { data: 'employee_count', name: 'employee_count' },
            { data: 'created_at', name: 'created_at' },
            { data: 'actions', name: 'actions', orderable: false, searchable: false }
        ],
    });
    // Open modal
    $(document).on('click', '.edit-employeetype', function() {
        $('#employee_type_id').val($(this).data('id'));
        $('#employee_type_name').val($(this).data('name'));
        $('#editEmployeeTypeModal').modal('show');
    });

    // Submit modal form
    $('#editEmployeeTypeForm').on('submit', function(e) {
        e.preventDefault();

        let id = $('#employee_type_id').val();
        let url = `/admin/employment-types/${id}`;

        $.ajax({
            url: url,
            type: 'POST',
            data: $(this).serialize(),
            success: function(res) {
                $('#editEmployeeTypeModal').modal('hide');
                table.ajax.reload();

                let alertBox = $('#edit-alert .alert');
                let icon = $('#edit-alert .alert-icon-change');

                // Reset classes
                alertBox.removeClass('alert-solid-danger alert-solid-success');
                icon.removeClass('ti tabler-ban ti tabler-check');

                // Add success styles
                alertBox.addClass('alert-solid-success');
                icon.addClass('ti tabler-check');

                $('#edit-alert').show();
                $('.edit-msg').text('Employee Type Record Updated Successfully');

                setTimeout(function() {
                    $('#edit-alert').fadeOut();
                }, 1000);
            },
            error: function(xhr) {
                let alertBox = $('#edit-alert .alert');
                let icon = $('#edit-alert .alert-icon-change');

                $('#editEmployeeTypeModal').modal('hide');

                // Reset classes
                alertBox.removeClass('alert-solid-success alert-solid-danger');
                icon.removeClass('ti tabler-check ti tabler-ban');

                // Add error styles
                alertBox.addClass('alert-solid-danger');
                icon.addClass('ti tabler-ban');

                $('#edit-alert').show();
                $('.edit-msg').text('This Role name already exists');

                setTimeout(function() {
                    $('#edit-alert').fadeOut();
                }, 1000);
            }

        });
    });
});



</script>

@endsection
