@extends('admin.layouts.app')
@section('admin_content')

<div class="container-xxl flex-grow-1 container-p-y">

        <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>
            <div class="col-md-5 custom-title-col">
                <h4 class='mb-0 custom-page-title'>Client Profile - {{ $client->name }}</h4>
                <p>Add client details.</p>
            </div>
            <div class="col-md-5 text-end">
                <a href="{{ route('add.client') }}" class='btn cstm-btn-link-2 text-white'>Add</a>
                <a href="{{ route('all.clients') }}" class='btn cstm-btn-link text-white'>Clients</a>
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
                    @include('admin.client.profile_layouts.nav')
                    <div class="custom-card-body w-100">
                        <table class="table" id="attendanceTable" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Date</th>
                                    <th>Clock In</th>
                                    <th>Clock Out</th>
                                    <th>Total Hours</th>
                                    <th>Late Minutes</th>
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
    $('#attendanceTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: '{{ route('daily.report', $client->id) }}',
        columns: [
            { data: 'employee_name', name: 'employee_name' },
            { data: 'date', name: 'date' },
            { data: 'clock_in', name: 'clock_in' },
            { data: 'clock_out', name: 'clock_out' },
            { data: 'total_minutes', name: 'total_minutes' },
            { data: 'late_minutes', name: 'late_minutes' },
        ]
    });
});
</script>

@endsection
