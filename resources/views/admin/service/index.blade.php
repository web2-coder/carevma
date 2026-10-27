@extends('admin.layouts.app')

@section('admin_content')
<div class="container-xxl flex-grow-1 container-p-y">
    <div class="d-flex justify-content-between align-items-center">
        <h4>Services</h4>
        <button class="btn btn-primary mb-3" id="addServiceBtn" data-bs-toggle="modal" data-bs-target="#serviceModal">
            Add New Service
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
        <table class="table table-bordered" id="services-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Service Name</th>
                    <th>Created At</th>
                    <th>Action</th>
                </tr>
            </thead>
        </table>
    </div>
   </div>
</div>

<!-- Service Modal (used for Add & Edit) -->
<div class="modal fade" id="serviceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form id="serviceForm" method="POST">
            @csrf
            <input type="hidden" name="_method" id="form-method" value="POST">

            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="serviceModalTitle">Add New Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="service-id" name="id">

                    <div class="mb-3">
                        <label>Name</label>
                        <input type="text" placeholder='IT Support Service' id="service-name" name="name" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-success" id="saveServiceBtn">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(function() {
    let table = $('#services-table').DataTable({
        processing: true,
        serverSide: true,
        ajax: "{{ route('services.index') }}",
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
                        <form action="/admin/services/${row.id}" 
                                method="POST" 
                                style="display:inline-block;" 
                                onsubmit="return confirm('Are you sure you want to delete this service?');">
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
    $("#addServiceBtn").on("click", function() {
        $("#serviceModalTitle").text("Add New Service");
        $("#serviceForm").attr("action", "{{ route('services.store') }}");
        $("#form-method").val("POST");
        $("#service-id").val("");
        $("#service-name").val("");
    });

    // Open Modal for Edit
    $(document).on("click", ".edit-btn", function() {
        let id = $(this).data("id");
        let name = $(this).data("name");

        $("#serviceModalTitle").text("Edit Service");
        $("#serviceForm").attr("action", "/admin/services/" + id);
        $("#form-method").val("PUT");
        $("#service-id").val(id);
        $("#service-name").val(name);

        $("#serviceModal").modal("show");
    });
});
</script>
@endsection