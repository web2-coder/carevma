@extends('admin.layouts.app')
@section('admin_content')

<div class="container-xxl flex-grow-1 container-p-y">

    <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>
        <div class="col-md-5 custom-title-col">
            <h4 class='mb-0 custom-page-title'>Add Invoice</h4>
            <p>Add client employee invoices details.</p>
        </div>
        <div class="col-md-5 text-end">
            <a href="{{ route('admin.invoices') }}" class='btn cstm-btn-link text-white'>Invoices</a>
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
                <form method="POST" action="{{ route('employee_salaries.storeInvoice') }}">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label custom-page-title">Select Client</label>
                            <select name="client_id" id="client_id" class="select2 form-select client-select" required>
                                <option></option>
                                @foreach($clients as $client)
                                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                                @endforeach
                            </select>
                            <div class="client-details mt-2 text-dark small" style='display:none;'></div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label>From Date</label>
                                <input type="date" class="form-control date-from" required>
                            </div>
                            <div class="col-md-6">
                                <label>To Date</label>
                                <input type="date" class="form-control date-to" required>
                            </div>
                        </div>


                        <div class="mb-3">
                            <button type="button" class="btn btn-primary btn-generate">Generate</button>
                        </div>

                        <div class="salary-details mt-3" style="display:none;">
                            <div class="row">
                            <div class="col-md-3">
                                <label>Client</label>
                                <input type="text" name="client_name" class="form-control client_name" disabled>
                            </div>
                            <div class="col-md-3">
                                <label>Employee</label>
                                <input type="text" name="employee" class="form-control employee_name" disabled>
                            </div>    
                            <div class="col-md-3">
                                    <label>Total Hours</label>
                                    <input type="number" step="0.01" name="total_hours" class="form-control total-hours">
                                    <small class="text-muted human-time"></small>

                                </div>
                                <div class="col-md-3">
                                    <label>Late Minutes</label>
                                    <input type="number" name="total_late" class="form-control total-late">
                                </div>
                                <div class="col-md-3">
                                    <label>Overtime (minutes)</label>
                                    <input type="number" name="total_overtime" class="form-control total-overtime">
                                </div>
                                <div class="col-md-3">
                                    <label>Salary</label>
                                    <input type="number" step="0.01" name="salary_amount" class="form-control salary-amount">
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 text-end">
                            <button type="submit" class="btn btn-success">Save Invoice</button>
                        </div>

                      
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
$(document).ready(function () {

    // generate salary
    $('.btn-generate').on('click', function () {
        let $container = $('.salary-details');
        let employeeId = $('#employee_id').val();
        let clientId = $('#client_id').val();
        let dateFrom = $('.date-from').val();
        let dateTo   = $('.date-to').val();

        if (!dateFrom || !dateTo || !clientId) {
        alert("Please select both From and To dates");
        return;
        }

        $.ajax({
            url: "{{ route('employee_salaries.generate') }}",
            type: "POST",
            data: {
                _token: "{{ csrf_token() }}",
                client_id: clientId,
                date_from: dateFrom,
                date_to: dateTo
            },
            success: function (res) {
    if (res.employees && res.employees.length > 0) {
        let html = '';
        res.employees.forEach(emp => {
            html += `
                <div class="employee-salary-block border p-3 mb-3">
                    <h6>${emp.employee_name}</h6>
                    <input type="hidden" name="employees[${emp.employee_id}][employee_id]" value="${emp.employee_id}">
                    
                    <div class="row">
                        <div class="col-md-3">
                            <label>Total Hours</label>
                            <input type="number" step="0.01" class="form-control" 
                                name="employees[${emp.employee_id}][total_hours]" 
                                value="${emp.regular_hours}">
                            <small class="text-muted hours-human"></small>
                        </div>
                        <div class="col-md-3">
                            <label>Late Minutes</label>
                            <input type="number" class="form-control" 
                                name="employees[${emp.employee_id}][total_late]" 
                                value="${emp.total_late}">
                        </div>
                        <div class="col-md-3">
                            <label>Overtime (minutes)</label>
                            <input type="number" class="form-control" 
                                name="employees[${emp.employee_id}][total_overtime]" 
                                value="${emp.total_overtime}">
                        </div>
                        <div class="col-md-3">
                            <label>Salary</label>
                            <input type="number" step="0.01" class="form-control" 
                                name="employees[${emp.employee_id}][salary_amount]" 
                                value="${emp.salary_amount}">
                                <input type="hidden" value="${emp.period_start}" name="period_start" class="period-start">
                                <input type="hidden" value="${emp.period_end}" name="period_end" class="period-end">
                        </div>
                    </div>
                </div>
            `;
        });

        $('.salary-details').html(html).show();

        // Trigger human readable time
        $('.salary-details .form-control[name*="[total_hours]"]').trigger('input');
    } else {
        alert("No employees found for this client in selected month.");
    }
},
            error: function (xhr) {
                alert(xhr.responseJSON?.error || "Error while generating salary.");
            }
        });
    });

    $(document).on('input', '.total-hours', function () {
    let hours = parseFloat($(this).val()) || 0;
    let totalMinutes = Math.round(hours * 60);
    let h = Math.floor(totalMinutes / 60);
    let m = totalMinutes % 60;

    let text = '';
    if (h > 0) text += h + ' hour' + (h > 1 ? 's' : '');
    if (m > 0) text += (text ? ' ' : '') + m + ' minute' + (m > 1 ? 's' : '');
    if (!text) text = '0 minutes';

    $(this).closest('.col-md-3').find('.human-time').text(text);
});

});
</script>
@endsection
