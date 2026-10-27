@extends('doctor.layouts.app')

@section('doctor_content')
    <div class="container-xxl flex-grow-1 container-p-y">
        <h4 class="fw-bold mb-4">Assigned Employees</h4>

        @if ($employees->isEmpty())
            <p>No employees assigned to you.</p>
        @else

            @php
                // Remove duplicate employees by name + email
                $uniqueEmployees = $employees->unique(fn($e) => $e->first_name.$e->last_name.$e->email);
            @endphp

            <div class="row g-3">
                @foreach ($uniqueEmployees as $e)
                    <div class="col-md-3">
                        <a href="{{ route('doctor.employee.attendance', $e->id) }}" class="text-decoration-none">
                            <div class="card p-3 text-center shadow-sm h-100 d-flex flex-column justify-content-center align-items-center">
                                <img src="{{ $e->profile_picture ? asset('storage/' . $e->profile_picture) : asset('profile.png') }}"
                                    alt="Profile" class="rounded-circle mb-2" width="70" height="70"
                                    style="object-fit:cover;">
                                <h6 class="mb-1 text-dark">{{ $e->first_name }} {{ $e->last_name }}</h6>
                                <small class="text-muted">{{ $e->email }}</small>
                        
                            <a href="{{ route('doctor.employee.attendance', $e->id) }}" class="text-decoration-none">
                    Check Attendance SR
                    
                            </a>
                  
                    
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>

        @endif
    </div>
@endsection
