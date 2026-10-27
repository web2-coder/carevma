@extends('admin.layouts.app')
@section('admin_content')
<div class="container-xxl flex-grow-1 container-p-y">

    <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>
        <div class="col-md-5 custom-title-col">
            <h4 class='mb-0 custom-page-title'>Shift Types</h4>
            <p>Add, edit and delete shift types.</p>
        </div>
        <div class="col-md-5 d-flex justify-content-end gap-2">
            <button type="button" data-bs-toggle="modal" data-bs-target="#addShiftTypeModal"
                class="cstm-btn-link text-white">Add Shift Type</button>
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
                <div class="alert alert-solid-success">{{ session('success') }}</div>
            @endif
        </div>
    </div>

    <div class="row d-flex justify-content-center mt-5 align-items-center mb-5">
        <div class="col-md-10">
            <div class="nav-align-left">
                @include('admin.layouts.employee_options_layouts') <!-- Sidebar -->
                <div class="custom-card-body w-100">
                    <table id="shiftTypesTable" class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
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
<div class="modal fade" id="addShiftTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="{{ route('shift-types.store') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 class="custom-page-title">Add Shift Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Name</label>
                        <input type="text" name="name" class="form-control" required>
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
<div class="modal fade" id="editShiftTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="editShiftTypeForm" method="POST">
                @csrf
                @method('PUT')
                <input type="hidden" name="id" id="shift_type_id">

                <div class="modal-header">
                    <h5 class="modal-title">Edit Shift Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label>Name</label>
                        <input type="text" name="name" id="shift_type_name" class="form-control" required>
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
$(function(){
    var table = $('#shiftTypesTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: "{{ route('shift-types.index') }}",
        columns:[
            { data:'id', name:'id' },
            { data:'name', name:'name' },
            { data:'created_at', name:'created_at' },
            { data:'actions', name:'actions', orderable:false, searchable:false }
        ]
    });

    // Open modal
    $(document).on('click', '.edit-shifttype', function(){
        $('#shift_type_id').val($(this).data('id'));
        $('#shift_type_name').val($(this).data('name'));
        $('#editShiftTypeModal').modal('show');
    });

    // Submit edit form
    $('#editShiftTypeForm').on('submit', function(e){
        e.preventDefault();
        let id = $('#shift_type_id').val();
        let url = /admin/shift-types/${id};
        $.ajax({
            url: url,
            type: 'POST',
            data: $(this).serialize(),
            success:function(res){
                $('#editShiftTypeModal').modal('hide');
                table.ajax.reload();
                $('#edit-alert').show().find('.edit-msg').text('Shift Type updated successfully');
                setTimeout(()=>$('#edit-alert').fadeOut(),1000);
            },
            error:function(){
                $('#editShiftTypeModal').modal('hide');
                $('#edit-alert').show().find('.edit-msg').text('Error updating Shift Type');
                setTimeout(()=>$('#edit-alert').fadeOut(),1000);
            }
        });
    });
});
</script>
@endsection
