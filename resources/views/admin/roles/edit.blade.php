@extends('admin.layouts.app')
@section('admin_content')
<div class="container">
    <h2>Edit Role</h2>
    <form action="{{ route('roles.update',$role->id) }}" method="POST">
        @csrf @method('PUT')
        <div class="mb-3">
            <label>Name</label>
            <input type="text" name="name" class="form-control" value="{{ $role->name }}" required>
            @error('name') <small class="text-danger">{{ $message }}</small>@enderror
        </div>
        <button type="submit" class="btn btn-warning">Update</button>
        <a href="{{ route('roles.index') }}" class="btn btn-secondary">Back</a>
    </form>
</div>
@endsection
