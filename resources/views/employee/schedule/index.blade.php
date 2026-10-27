@extends('employee.layouts.app')
@section('employee_content')

    <div class="container-xxl flex-grow-1 container-p-y">

        <!-- Top Section -->
        <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>

            <div class="col-md-10">
                <div class="custom-card-body">
                    <div class="d-flex align-items-start row">
                        
                        @if($schedules->isEmpty())
                            <div class="alert alert-info">No schedules assigned yet.</div>
                        @else
                            <table class="table table-bordered mt-3">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Client</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($schedules as $schedule)
                                        <tr>
                                            <td>{{ $schedule->weekday }}</td>
                                            <td>{{ $schedule->start_time }} - {{ $schedule->end_time }}</td>
                                            <td>{{ $schedule->client->name }}</td>
                                            <td>
                                                <span class="badge bg-{{ $schedule->status == 'confirmed' ? 'success' : ($schedule->status == 'pending' ? 'warning' : 'secondary') }}">
                                                    {{ ucfirst($schedule->status) }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection