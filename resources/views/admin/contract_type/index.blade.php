@extends('admin.layouts.app')

@section('admin_content')
<div class="container-xxl flex-grow-1 container-p-y">
    <div class="d-flex justify-content-between align-items-center">
        <h4>Contract Types</h4>
        <button class="btn btn-primary mb-3" id="addContract TypeBtn" data-bs-toggle="modal" data-bs-target="#contractModal">
            Add New Contract Type
        </button>
    </div>    


    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
                        <div class="alert alert-danger">
                            <ul class='list-unstyled p-0 m-0'>
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

   <div class="card">
    <div class="card-body">
        <table class="table table-bordered" id="contracts-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Contract Type Name</th>
                    <th>Created At</th>
                    <th>Action</th>
                </tr>
            </thead>
        </table>
    </div>
   </div>
</div>

<!-- Contract Type Modal (used for Add & Edit) -->
<div class="modal fade" id="contractModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="contractForm" method="POST">
            @csrf
            <input type="hidden" name="_method" id="form-method" value="POST">

            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="contractModalTitle">Add New Contract Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="contract-id" name="id">

                    <div class="mb-3">
                        <label>Name</label>
                        <input type="text" placeholder='IT Support Contract Type' id="contract-name" name="name" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-success" id="saveContract TypeBtn">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(function() {
    let table = $('#contracts-table').DataTable({
        processing: true,
        serverSide: true,
        ajax: "{{ route('contracts.index') }}",
        columns: [
            {data: 'id', name: 'id'},
            {data: 'name', name: 'name'},
            {data: 'created_at', name: 'created_at'},
            {
                data: null,
                render: function (data, type, row) {
                    return `
                            <button class="btn btn-sm btn-warning edit-btn" 
                                data-id="${row.id}" 
                                data-name="${row.name}">
                                Edit
                            </button>
                            <form action="/admin/contracts/${row.id}" 
                                method="POST" 
                                style="display:inline-block;" 
                                onsubmit="return confirm('Are you sure you want to delete this contract type?');">
                                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                <input type="hidden" name="_method" value="DELETE">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        `;
                }
            }
        ]
    });

    // Open Modal for Add
    $("#addContract TypeBtn").on("click", function() {
        $("#contractModalTitle").text("Add New Contract Type");
        $("#contractForm").attr("action", "{{ route('contracts.store') }}");
        $("#form-method").val("POST");
        $("#contract-id").val("");
        $("#contract-name").val("");
    });

    // Open Modal for Edit
    $(document).on("click", ".edit-btn", function() {
        let id = $(this).data("id");
        let name = $(this).data("name");

        $("#contractModalTitle").text("Edit Contract Type");
        $("#contractForm").attr("action", "/admin/contracts/" + id);
        $("#form-method").val("PUT");
        $("#contract-id").val(id);
        $("#contract-name").val(name);

        $("#contractModal").modal("show");
    });
});
</script>
@endsection