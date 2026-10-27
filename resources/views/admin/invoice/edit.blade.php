@extends('admin.layouts.app')
@section('admin_content')

    <div class="container-xxl flex-grow-1 container-p-y">

        <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>
            <div class="col-md-5 custom-title-col">
                <h4 class='mb-0 custom-page-title'>Invoices  - {{ $invoice->invoice_number }}</h4>
                <p>Edit client invoice details.</p>
            </div>
            <div class="col-md-5 text-end">
                <a href="{{ route('admin.add.invoice') }}" class='btn cstm-btn-link-2 text-white'>Add</a>
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

                        <form method="POST" action="{{ route('invoices.update', $invoice->id) }}">
                            @csrf
                            @method('PUT')

                            {{-- Invoice Information --}}
                                <div class="basic-info-box">
                                    <h5>Invoice Information</h5>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label>Invoice Number</label>
                                            <input type="text" class="form-control" value="{{ $invoice->invoice_number }}"
                                                disabled>
                                        </div>
                                        <div class="col-md-6">
                                            <label>Client</label>
                                            <input type="text" class="form-control" value="{{ $invoice->client->name }}"
                                                disabled>
                                        </div>
                                    </div>

                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label>From Date</label>
                                            <input type="date" name="period_start" class="form-control"
                                                value="{{ $invoice->period_start->format('Y-m-d') }}">
                                        </div>
                                        <div class="col-md-6">
                                            <label>To Date</label>
                                            <input type="date" name="period_end" class="form-control"
                                                value="{{ $invoice->period_end->format('Y-m-d') }}">
                                        </div>
                                    </div>
                                </div>
                            

                            {{-- Employee Salaries --}}
                            <div class="salaries-box">
                               
                                    <h5>Employee Salaries</h5>

                                    @foreach($invoice->salaries as $salary)
                                        <div class="employee-salary p-3 mb-3">
                                            <h6>{{ $salary->employee->first_name }} {{ $salary->employee->last_name }}</h6>
                                            <input type="hidden" name="employees[{{ $salary->id }}][id]"
                                                value="{{ $salary->id }}">

                                            <div class="row">
                                                <div class="col-md-3">
                                                    <label>Total Hours</label>
                                                    <input type="number" step="0.01" class="form-control"
                                                        name="employees[{{ $salary->id }}][total_hours]"
                                                        value="{{ $salary->total_hours }}">
                                                </div>
                                                <div class="col-md-3">
                                                    <label>Late Minutes</label>
                                                    <input type="number" class="form-control"
                                                        name="employees[{{ $salary->id }}][total_late]"
                                                        value="{{ $salary->total_late }}">
                                                </div>
                                                <div class="col-md-3">
                                                    <label>Overtime (minutes)</label>
                                                    <input type="number" class="form-control"
                                                        name="employees[{{ $salary->id }}][total_overtime]"
                                                        value="{{ $salary->total_overtime }}">
                                                </div>
                                                <div class="col-md-3">
                                                    <label>Salary Amount</label>
                                                    <input type="number" step="0.01" class="form-control"
                                                        name="employees[{{ $salary->id }}][salary_amount]"
                                                        value="{{ $salary->salary_amount }}">
                                                </div>
                                            </div>
                                        </div>
                                        <hr>
                                    @endforeach
                               
                            </div>

                            <div class="text-end">
                                <button type="submit" class="btn cstm-btn-link text-white">Update Invoice</button>
                            </div>

                        </form>

                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection