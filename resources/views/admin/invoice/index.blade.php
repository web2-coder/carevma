@extends('admin.layouts.app')
@section('admin_content')

<div class="container-xxl flex-grow-1 container-p-y">

        <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>
            <div class="col-md-5 custom-title-col">
                <h4 class='mb-0 custom-page-title'>Invoices</h4>
                <p>Add client employee invoices details.</p>
            </div>
            <div class="col-md-5 text-end">
                <a href="{{ route('admin.add.invoice') }}" class='btn cstm-btn-link text-white'>Add</a>
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
                    <div class="custom-card-body w-100">
                    <table class="table table-bordered" id="invoices-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Invoice #</th>
                                <th>Client</th>
                                <th>Period From</th>
                                <th>Period To</th>
                                <th>Total Hours</th>
                                <th>Total Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                    </table>
                    </div>
                </div>
        </div>
    </div>
</div>


<script>
$(function () {
    $('#invoices-table').DataTable({
        processing: true,
        serverSide: true,
        ajax: '{{ route('admin.invoices') }}',
        columns: [
            { data: 'id', name: 'id' },
            { data: 'invoice_number', name: 'invoice_number' },
            { data: 'client', name: 'client.name' },
            { data: 'period_from', name: 'period_from', orderable: false, searchable: false },
            { data: 'period_to', name: 'period_to', orderable: false, searchable: false },
            { data: 'total_hours', name: 'total_hours' },
            { data: 'total_amount', name: 'total_amount' },
            { data: 'action', name: 'action', orderable: false, searchable: false },
        ]
    });
});
</script>

@endsection
