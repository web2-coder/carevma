@extends('employee.layouts.app')
@section('employee_content')


    <div class="container-xxl flex-grow-1 container-p-y">

        <div class='row d-flex justify-content-center mt-5 align-items-center'>
            <div class="col-md-10 custom-title-col">
                <h4 class='mb-0 custom-page-title'>Setting</h4>
                <p>you can change your account details.</p>
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
        <div class="row d-flex justify-content-center mt-5 align-items-center">
            <div class="col-md-10">
                <div class="custom-card-body w-50">
                    <h5 class="mb-1 custom-page-title">My social logins</h5>
                    <p class="mb-5">Link social profiles for easier access to your carevma account.</p>
                    @if(Auth::user()->google_id)
                        <div class="d-flex justify-content-between align-items-center" style="margin-top:40px;">
                            <div class="d-flex gap-2 align-items-center">
                                <img class="google-img" src="{{ asset('google-symbol.png') }}" alt="">
                                <h6 class="mb-0 custom-page-title">Google</h6>
                            </div>
                            <form action="{{ route('google.disconnect') }}" method="POST">
                                @csrf
                                <button type="submit" class="btn cstm-link-btn-2">
                                    Disconnect
                                </button>
                            </form>
                        </div>
                    @else
                        <div class="d-flex justify-content-between align-items-center" style="margin-top:40px;">
                            <div class="d-flex gap-2 align-items-center">
                                <img class="google-img" src="{{ asset('google-symbol.png') }}" alt="">
                                <h6 class="mb-0 custom-page-title">Google</h6>
                            </div>
                            <a href="{{ route('google.connect') }}" class="btn cstm-link-btn">
                                Connect
                            </a>
                        </div>
                    @endif
                </div>
            </div>

            <div class="col-md-10 mt-5">
                <div class="custom-card-body w-50">
                    <h5 class="mb-1 custom-page-title">Change Password</h5>
                    <p class="mb-5">you can change your password</p>

                    <form action="{{ route('employees.change-password') }}" method="POST" class="pwd-change-form">
                        @csrf
                        @method('PUT')

                        <div class="mb-4">
                            <input type="hidden" name="employee_id" value="{{ $employee->id }}">
                            <label class="form-label" for="password">Password</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="password" id="password" class="form-control" name="password" required
                                    placeholder="••••••••" aria-describedby="password" />
                                <span class="cursor-pointer">
                                    <i class="icon-base ti tabler-eye-off"></i>
                                </span>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="password-confirmed">Confirm Password</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="password" id="password-confirmed" class="form-control"
                                    name="password_confirmation" required placeholder="••••••••"
                                    aria-describedby="password" />
                                <span class="cursor-pointer">
                                    <i class="icon-base ti tabler-eye-off"></i>
                                </span>
                            </div>
                        </div>

                        <button type="submit" class="btn cstm-btn-link text-white">Change Password</button>
                    </form>

                </div>
            </div>

        </div>
    </div>

@endsection