@extends('admin.layouts.app')
@section('admin_content')
<div class="container">
    <h2>Edit Department</h2>

    <form action="{{ route('departments.update', $department) }}" method="POST">
        @csrf
        @method('PUT')
        <div class="mb-3">
            <label>Name</label>
            <input type="text" name="name" value="{{ $department->name }}" class="form-control" required>
            @error('name') <small class="text-danger">{{ $message }}</small> @enderror
        </div>
        <button class="btn btn-success">Update</button>
    </form>
</div>
@endsection
