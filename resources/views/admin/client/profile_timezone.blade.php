@extends('admin.layouts.app')
@section('admin_content')

<div class="container-xxl flex-grow-1 container-p-y">

        <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>
            <div class="col-md-5 custom-title-col">
                <h4 class='mb-0 custom-page-title'>Client Profile - {{ $client->name }}</h4>
                <p>Update client details.</p>
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
                   <form action="{{ route('client.update', $client->id) }}" method="POST" class="w-100">
                        @csrf
                        @method('PUT')
                        <div class="tab-content">
                            <div class="tab-pane active" style="opacity:1;" id="navs-pills-left-timezone" role="tabpanel">
                                <div class="col-12">
                                    <label class="form-label" for="timezone">Select Timezone</label>
                                    <select id="timezone" name="timezone"
                                        class="form-control @error('timezone') is-invalid @enderror">
                                        @foreach(timezone_identifiers_list() as $tz)
                                            <option value="{{ $tz }}" {{ $client->timezone == $tz ? 'selected' : '' }}>
                                                {{ $tz }}
                                            </option>
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
