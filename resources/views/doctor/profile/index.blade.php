@extends('doctor.layouts.app')

@section('doctor_content')
<div class="container-xxl flex-grow-1 container-p-y py-5">
  <div class='row d-flex justify-content-center mt-3 align-items-center'>
       <div class='col-md-10'>
            <div class="custom-card-body">
    <h4>My Profile</h4>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <form action="{{ route('doctor.profile.update') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label>Name</label>
            <input type="text" name="name" value="{{ $client->name }}" class="form-control" required>
        </div>

        <div class="mb-3">
            <label>Email</label>
            <input type="email" name="email" value="{{ $client->email }}" class="form-control" required>
        </div>

            {{-- <div class="mb-3">
                <label>Profile Picture</label>
                <input type="file" name="profile_picture" class="form-control">
                @if($client->profile_picture)
                    <img src="{{ asset('storage/'.$client->profile_picture) }}" alt="Profile" width="100" class="mt-2">
                @endif
            </div> --}}

        <button class="btn btn-primary">Update Profile</button>
    </form>

    <hr>

    <h5>Change Password</h5>
    <form action="{{ route('doctor.profile.changePassword') }}" method="POST">
        @csrf
        <div class="mb-3">
            <label>Current Password</label>
            <input type="password" name="current_password" class="form-control" required>
        </div>

        <div class="mb-3">
            <label>New Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>

        <div class="mb-3">
            <label>Confirm New Password</label>
            <input type="password" name="password_confirmation" class="form-control" required>
        </div>

        <button class="btn btn-warning">Change Password</button>
    </form>
</div>
</div>
</div>
</div>
@endsection
