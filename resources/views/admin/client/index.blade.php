
@extends('admin.layouts.app')
@section('admin_content')
<div class="container-xxl flex-grow-1 container-p-y">
    <div class='row d-flex justify-content-center mt-5 align-items-center'>
        <div class="col-md-5 custom-title-col">
            <h4 class='mb-0 custom-page-title'>Clients</h4>
            <p>View, add, edit and delete client details.</p>
        </div>
        <div class="col-md-5 text-end">
            <a href="{{ route('add.client') }}" class='btn cstm-btn-link text-white'>Add</a>
        </div>
    </div>

    <div class='row d-flex justify-content-center mt-5 align-items-center'>
        <div class="col-md-10">  
            <div class="custom-card-body" style="overflow-x:auto;">
                <table id="clients-table" class="table table-bordered w-100">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Business Name</th>
                            <th>Country</th>
                            <th>Charges (per hr)</th>
                            <th>Timezone</th>
                            <th>Contract</th>
                            <th>Service</th>
                            <th>Ring Center</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(function() {
    $('#clients-table').DataTable({
        processing: true,
        serverSide: true,
        ajax: '{{ route('all.clients') }}',
        columns: [
            { data: 'id', name: 'id' },
            { data: 'client_info', name: 'client_info' },
            { data: 'business_name', name: 'business_name' },
            { data: 'country', name: 'country' },
            { data: 'per_hour_charges', name: 'per_hour_charges' },
            { data: 'timezone', name: 'timezone' },
            { data: 'contract_type', name: 'contractType.name', orderable: false, searchable: false },
            { data: 'service', name: 'service.name', orderable: false, searchable: false },
            { data: 'ring_center', name: 'ring_center', orderable: false, searchable: false },
            { data: 'actions', name: 'actions', orderable: false, searchable: false },
        ]
    });
});
</script>

@endsection