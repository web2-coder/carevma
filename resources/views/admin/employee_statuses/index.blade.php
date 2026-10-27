@extends('admin.layouts.app')
@section('admin_content')
<div class="container-xxl flex-grow-1 container-p-y">

    <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>
        <div class="col-md-5 custom-title-col">
            <h4 class='mb-0 custom-page-title'>Employee Statuses</h4>
            <p>Add, edit and delete employee status details.</p>
        </div>
        <div class="col-md-5 d-flex justify-content-end gap-2">
            <button type="button" data-bs-toggle="modal" data-bs-target="#addStatusModal" class="cstm-btn-link text-white">
                Add Status
            </button>
        </div>

        <div class="col-md-10 mt-5" id="edit-alert" style="display:none;">
            <div class="alert alert-solid-success d-flex align-items-center" role="alert">
                <span class="alert-icon rounded"><i class="icon-base ti tabler-check icon-md"></i></span>
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
                @include('admin.layouts.employee_options_layouts')
                <div class="custom-card-body w-100">
                    <table id="statusesTable" class="table">
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
<div class="modal fade" id="addStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="{{ route('employee-statuses.store') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 class="custom-page-title">Add Employee Status</h5>
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
<div class="modal fade" id="editStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="editStatusForm" method="POST">
                @csrf
                @method('PUT')
                <input type="hidden" name="id" id="status_id">

                <div class="modal-header">
                    <h5 class="modal-title">Edit Employee Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label>Name</label>
                        <input type="text" name="name" id="status_name" class="form-control" required>
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
    var table = $('#statusesTable').DataTable({
        processing:true,
        serverSide:true,
        ajax:"{{ route('employee-statuses.index') }}",
        columns:[
            { data:'id', name:'id' },
            { data:'name', name:'name' },
            { data:'created_at', name:'created_at' },
            { data:'actions', name:'actions', orderable:false, searchable:false }
        ]
    });

    // Open edit modal
    $(document).on('click','.edit-status',function(){
        $('#status_id').val($(this).data('id'));
        $('#status_name').val($(this).data('name'));
        $('#editStatusModal').modal('show');
    });

    // Submit edit form
    $('#editStatusForm').on('submit',function(e){
        e.preventDefault();
        let id = $('#status_id').val();
        let url = /admin/employee-statuses/${id};
        $.ajax({
            url:url,
            type:'POST',
            data:$(this).serialize(),
            success:function(){
                $('#editStatusModal').modal('hide');
                table.ajax.reload();
                $('#edit-alert').show().find('.edit-msg').text('Employee Status updated successfully');
                setTimeout(()=>$('#edit-alert').fadeOut(),1000);
            },
            error:function(){
                $('#editStatusModal').modal('hide');
                $('#edit-alert').show().find('.edit-msg').text('Error updating status');
                setTimeout(()=>$('#edit-alert').fadeOut(),1000);
            }
        });
    });
});
</script>
@endsection