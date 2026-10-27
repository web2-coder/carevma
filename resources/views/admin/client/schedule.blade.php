@extends('admin.layouts.app')
@section('admin_content')

    <div class="container-xxl flex-grow-1 container-p-y">

        <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>
            <div class="col-md-5 custom-title-col">
                <h4 class='mb-0 custom-page-title'>Client Profile - {{ $client->name }}</h4>
                <p>Add client details.</p>
            </div>
            <div class="col-md-5 text-end">
                <a href="{{ route('add.client') }}" class='btn cstm-btn-link-2 text-white'>Add</a>
                <a href="{{ route('all.clients') }}" class='btn cstm-btn-link text-white'>Clients</a>
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

        <div class="row d-flex justify-content-center mt-5 align-items-center mb-5">
            <div class="col-md-10">
                <div class="nav-align-left">
                    @include('admin.client.profile_layouts.nav')
                    @php
                        // group schedules by employee
                        $groupedSchedules = $client->employeeSchedules->groupBy('employee_id');
                    @endphp

                    <div class="w-100">
                        @forelse($groupedSchedules as $employeeId => $schedules)
                 @php
    $employee = $schedules->first()->employee;
    $rate = $employee->clientRates
                ->firstWhere('client_id', $client->id)
                ->per_hour_rate ?? 0;
@endphp
                            <div class="custom-card-body w-100 mb-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="custom-page-title mb-0">{{ $employee->first_name }}
                                            {{ $employee->last_name }}</h6>
                                        <span class="mb-2" style="font-size:12px;">{{ $employee->email }}</span>
                                    </div>
                                    <h5 class="mb-0 text-dark fw-bold">${{ $rate }}/hr</h5>
                                </div>
                                <hr>
                                <table class="table table-borderless mb-0">
                                    <tbody>
                                        @foreach($schedules as $schedule)
                                            <tr>
                                                <td style="font-weight:700;color:black !important;">
                                                    {{ ucfirst($schedule->weekday) }}
                                                </td>
                                                <td>
                                                    {{ \Carbon\Carbon::parse($schedule->start_time)->format('h:i A') }}
                                                    -
                                                    {{ \Carbon\Carbon::parse($schedule->end_time)->format('h:i A') }}
                                                </td>
                                                <td>
                                                    {{ number_format($schedule->no_of_hours, 2) }} hrs
                                                </td>
                                                <td>
                                                    <span class="badge bg-info">{{ $schedule->timezone ?? '-' }}</span>
                                                </td>
                                                <td>
                                                    @if(!$schedule->enabled)
                                                        <span class="text-danger">Disabled</span>
                                                    @else
                                                        <span class="text-success">Enabled</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button type="button" class="cstm-dots-btn dropdown-toggle hide-arrow"
                                                            data-bs-toggle="dropdown" aria-expanded="false">
                                                            <i class="ti tabler-dots-vertical"></i>
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                            <li>
                                                                <a href="javascript:void(0)" class="dropdown-item edit-schedule"
                                                                    data-id="{{ $schedule->id }}"
                                                                    data-weekday="{{ $schedule->weekday }}"
                                                                    data-start="{{ $schedule->start_time }}"
                                                                    data-end="{{ $schedule->end_time }}"
                                                                    data-hours="{{ $schedule->no_of_hours }}"
                                                                    data-enabled="{{ $schedule->enabled }}">
                                                                    <i class="ti tabler-edit me-1"></i> Edit
                                                                </a>
                                                            </li>
                                                            <li>

                                                                <form action="{{ route('schedules.destroy', $schedule->id) }}"
                                                                    method="POST">
                                                                    @csrf
                                                                    @method('DELETE')
                                                                    <button type="submit" class="dropdown-item text-danger">
                                                                        <i class="ti tabler-trash me-1"></i> Delete
                                                                    </button>
                                                                </form>

                                                            </li>
                                                        </ul>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @empty
                            <div class="col-12">
                                <div class="alert alert-info">No employees assigned to this client.</div>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editScheduleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">

                <form action="{{ route('schedules.update') }}" method="POST">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="id" id="schedule_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Edit Schedule</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Weekday</label>
                            <input type="text" name="weekday" id="weekday" class="form-control" readonly>
                        </div>

                        <div class="mb-3">
                            <label>Start Time</label>
                            <input type="time" name="start_time" id="start_time" class="form-control"
                                placeholder="HH:MM:SS">
                        </div>

                        <div class="mb-3">
                            <label>End Time</label>
                            <input type="time" name="end_time" id="end_time" class="form-control" placeholder="HH:MM:SS">
                        </div>

                        <div class="mb-3">
                            <label>No of Hours</label>
                            <input type="number" step="0.01" name="no_of_hours" id="no_of_hours" class="form-control">
                        </div>

                        <div class="mb-3">
                            <label>Status</label>
                            <select name="enabled" id="enabled" class="form-control">
                                <option value="1">Enabled</option>
                                <option value="0">Disabled</option>
                            </select>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        $(document).on('click', '.edit-schedule', function () {
            function formatTime(time) {
                // If time has seconds, trim them
                return time ? time.substring(0, 5) : '';
            }
            $('#schedule_id').val($(this).data('id'));
            $('#weekday').val($(this).data('weekday'));
            $('#start_time').val(formatTime($(this).data('start')));
            $('#end_time').val(formatTime($(this).data('end')));
            $('#no_of_hours').val($(this).data('hours'));
            $('#enabled').val($(this).data('enabled'));

            $('#editScheduleModal').modal('show');
        });

        $(document).ready(function () {
            $(document).on('change', '#start_time, #end_time', function () {
                let start = $('#start_time').val();
                let end = $('#end_time').val();

                if (start && end) {
                    let startTime = new Date(`1970-01-01T${start}:00`);
                    let endTime = new Date(`1970-01-01T${end}:00`);
                    let diff = (endTime - startTime) / (1000 * 60 * 60);
                    if (diff < 0) diff += 24;
                    $('#no_of_hours').val(diff.toFixed(2));
                } else {
                    $('#no_of_hours').val('');
                }
            });
        });




    </script>

@endsection