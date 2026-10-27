@extends('admin.layouts.app')
@section('admin_content')

    <div class="container-xxl flex-grow-1 container-p-y">

        <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>
            <div class="col-md-5 custom-title-col">
                <h4 class='mb-0 custom-page-title'>Add Client</h4>
                <p>Add client details.</p>
            </div>
            <div class="col-md-5 text-end">
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

        <div class="row d-flex justify-content-center form-row">
            <div class="col-md-10">
                <div class="nav-align-left">
                    <ul class="nav nav-pills me-4" role="tablist">
                        <li class="nav-item">
                            <button type="button" class="nav-link active" role="tab" data-bs-toggle="tab"
                                data-bs-target="#navs-pills-left-basic" aria-controls="navs-pills-left-basic"
                                aria-selected="true">
                                Basic
                            </button>
                        </li>
                        <li class="nav-item">
                            <button type="button" class="nav-link" role="tab" data-bs-toggle="tab"
                                data-bs-target="#navs-pills-left-timezone" aria-controls="navs-pills-left-timezone"
                                aria-selected="false">
                                Timezone
                            </button>
                        </li>
                    </ul>
                    <form action="{{ route('insert.client') }}" method='POST' class="w-100">

                        @csrf
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="navs-pills-left-basic" role="tabpanel">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="client-name">Client Name</label>
                                        <input type="text" id="client-name" name="name"
                                            class="form-control @error('name') is-invalid @enderror" placeholder="John Doe"
                                            value="{{ old('name') }}" />
                                    </div>

                                    <!-- Client Email -->
                                    <div class="col-md-6">
                                        <label class="form-label" for="client-email">Client Email</label>
                                        <input type="email" id="client-email" name="email"
                                            class="form-control @error('email') is-invalid @enderror"
                                            placeholder="client@example.com" value="{{ old('email') }}" />
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="business-name">Business Name</label>
                                        <input type="text" id="business-name" name="business_name"
                                            class="form-control @error('business_name') is-invalid @enderror" placeholder="Acme Corp"
                                            value="{{ old('business_name') }}" />
                                    </div>

                                    <!-- Client Country -->
                                    <div class="col-md-6">
                                        <label class="form-label" for="client-country">Client Country</label>
                                        <select name="country" class="form-select">
                                            <option value="">-- Select Country --</option>
                                            @foreach($countries as $code => $name)
                                                <option value="{{ $code }}" {{ old('country') == $code ? 'selected' : '' }}>
                                                    {{ $name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="client-number">Client Number</label>
                                        <input type="text" id="client-number" name="phone_number"
                                            class="form-control @error('phone_number') is-invalid @enderror"
                                            placeholder="+92 300 1234567" value="{{ old('phone_number') }}" />
                                    </div>

                                    <!-- Client Per Hour Charges -->
                                    <div class="col-md-6">
                                        <label class="form-label" for="per-hour">Per Hour Charges($)</label>
                                            <input type="number" class="form-control @error('per_hour_charges') is-invalid @enderror""
                                        placeholder=" Amount" name='per_hour_charges' value="{{ old('per_hour_charges') }}"
                                                aria-label="Amount (to the nearest dollar)" />
                                    </div>
                                </div>

                                <div class="row mb-3">
                                      <!-- Contract Type -->
                                    <div class="col-md-6">
                                        <label class="form-label" for="contract-type">Contract Type</label>
                                        <select id="contract-type" name="contract_type_id"
                                            class="form-control @error('contract_type_id') is-invalid @enderror">
                                            <option value="">-- Select Contract Type --</option>
                                            @foreach($contractTypes as $contractType)
                                                <option value="{{ $contractType->id }}" {{ old('contract_type_id') == $contractType->id ? 'selected' : '' }}>
                                                    {{ $contractType->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <!-- Service -->
                                    <div class="col-md-6">
                                        <label class="form-label" for="service">Service</label>
                                        <select id="service" name="service_id"
                                            class="form-control @error('service_id') is-invalid @enderror">
                                            <option value="">-- Select Service --</option>
                                            @foreach($services as $service)
                                                <option value="{{ $service->id }}" {{ old('service_id') == $service->id ? 'selected' : '' }}>
                                                    {{ $service->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-6 mt-3">
                                        <label class="form-label">Ring Center</label>
                                        <div class="form-check mt-2">
                                            <input type="checkbox" id="ring-center" name="ring_center" value="1"
                                                class="form-check-input" {{ old('ring_center') ? 'checked' : '' }}>
                                            <label class="form-check-label" for="ring-center">Enable Ring Center</label>
                                        </div>
                                    </div>
                                </div>

                            </div>
                            <div class="tab-pane fade" id="navs-pills-left-timezone" role="tabpanel">
                                <div class="col-12">
                                    <label class="form-label" for="timezone">Select Timezone</label>
                                    <select id="timezone" name="timezone"
                                        class="form-control @error('timezone') is-invalid @enderror">
                                        <option value="">-- Select Timezone --</option>
                                        @foreach(timezone_identifiers_list() as $tz)
                                            <option value="{{ $tz }}" {{ old('timezone') == $tz ? 'selected' : '' }}>{{ $tz }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="mt-4 d-block">
                                <button type="submit" class="cstm-btn-link btn text-white me-4">Submit</button>
                                <button type="reset" class="cstm-btn-link-2 btn">Cancel</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>




@endsection