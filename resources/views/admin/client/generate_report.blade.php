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
                    <form id="salaryForm" method="POST" action="">
                        @csrf
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label for="month">Select Month</label>
                                <input type="month" id="month" name="month" class="form-control" required>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="button" id="generateBtn" class="btn btn-primary">Generate</button>
                            </div>
                        </div>

                        <div id="salaryDetails" style="display:none;">
                            <h5>Generated Salary</h5>
                            <div class="row">
                                <div class="col-md-3">
                                    <label>Total Hours</label>
                                    <input type="number" name="total_hours" id="total_hours" class="form-control">
                                    <small><span id="humanTime" class="text-muted"></span></small>

                                </div>
                                <div class="col-md-3">
                                    <label>Salary Amount</label>
                                    <input type="number" step="0.01" name="salary_amount" id="salary_amount" class="form-control">
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-12 text-end">
                                    <button type="submit" class="btn btn-success">Generate Invoice</button>
                                </div>
                            </div>
                        </div>
                    </form>

                    </div>
                </div>
        </div>
    </div>
</div>


<script>
function convertToHumanTime(decimalHours) {
    let hours = Math.floor(decimalHours); // whole hours
    let minutes = Math.round((decimalHours - hours) * 60); // remaining minutes
    return `${hours}h ${minutes}m`;
}

$(document).ready(function() {
    // Generate Salary
    $('#generateBtn').on('click', function() {
        let month = $('#month').val();
        if (!month) {
            alert("Please select a month");
            return;
        }

        $.ajax({
            url: "{{ route('employee_salaries.generate') }}",
            type: "POST",
            data: {
                _token: "{{ csrf_token() }}",
                employee_id: "{{ $employee->id }}", // pass employee ID
                client_id: "{{ $client->id }}",     // pass client ID
                month: month
            },
            success: function(res) {
                $('#total_hours').val(res.total_hours);
                $('#salary_amount').val(res.salary_amount);
                $('#salaryDetails').show();

                // also update human readable time
                $('#humanTime').text(convertToHumanTime(res.total_hours));
            },
            error: function() {
                alert("Error while generating salary.");
            }
        });
    });

    // Update human readable time when user edits hours manually
    $('#total_hours').on('change', function() {
        let val = parseFloat($(this).val());
        if (!isNaN(val)) {
            $('#humanTime').text(convertToHumanTime(val));
        } else {
            $('#humanTime').text('');
        }
    });
});
</script>


@endsection
