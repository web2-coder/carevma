@extends('admin.layouts.app')
@section('admin_content')
<div class="container">
    <h2>Create Role</h2>
    <form action="{{ route('roles.store') }}" method="POST">
        @csrf
        <div class="mb-3">
            <label>Name</label>
            <input type="text" name="name" class="form-control" required>
            @error('name') <small class="text-danger">{{ $message }}</small>@enderror
        </div>
        <button type="submit" class="btn btn-success">Save</button>
        <a href="{{ route('roles.index') }}" class="btn btn-secondary">Back</a>
    </form>
</div>
@endsection
