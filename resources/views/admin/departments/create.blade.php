@extends('admin.layouts.app')
@section('admin_content')
<div class="container">
    <h2>Add Department</h2>

    <form action="{{ route('departments.store') }}" method="POST">
        @csrf
        <div class="mb-3">
            <label>Name</label>
            <input type="text" name="name" class="form-control" required>
            @error('name') <small class="text-danger">{{ $message }}</small> @enderror
        </div>
        <button class="btn btn-success">Save</button>
    </form>
</div>
@endsection
