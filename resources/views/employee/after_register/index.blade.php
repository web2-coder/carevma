@extends('layouts.app')

@section('content')
<div class="vh-100">
    <div class="row h-100" style="background-image: url('/VMA-Hero_Image.jpg'); background-size:cover;">
        <div class="col-md-6 d-flex align-items-center justify-content-center">
            <div class="welcome-text-div">
                <h2>Welcome to CareVMA Health</h2>
                <p>Thanks for joining! Complete your profile so we can match you with the right shifts.</p>

                <form method="GET" action="{{ route('employee.completeProfile.start', $employee->id) }}">
                    @csrf
                    <input type="hidden" name="employee_id" value="{{ $employee->id }}">
                    <button type="submit" class="complete-profile-btn">
                        Start Completing Profile
                    </button>
                </form>
            </div>
        </div>
        <div class="col-md-6 p-0"></div>
    </div>
</div>
@endsection
