@extends('layouts.app')

@section('content')
<div class="container text-center mt-5">
    <h2>🎉 Profile Completed Successfully!</h2>
    <p>You can now access your dashboard and start using the system.</p>

    <a href="{{ route('login') }}" class="btn btn-primary mt-3">Go to Login</a>
</div>
@endsection
