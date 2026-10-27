@extends('admin.layouts.app')
@section('admin_content')

    <div class="container-xxl flex-grow-1 container-p-y">

        <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>
            <div class="col-md-5 custom-title-col">
                <h4 class='mb-0 custom-page-title'>Client Profile - {{ $client->name }}</h4>
                <p>Edit your client details.</p>
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
                    <div class="custom-card-body w-100">
                        <form method="POST" id="employeeScheduleForm" action='{{ route("employee.schedules.store") }}'>
                            @csrf
                            <input type="hidden" name='client_id' value='{{ $client->id }}'>

                            <div id="employeeSections">
                                <!-- Employee Schedule Template -->
                                <div class="employee-section mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h5 class="mb-3 custom-page-title">Employee Schedule</h5>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label custom-page-title">Select Employee</label>
                                        <select name="employees[0][employee_id]" class="select2 form-select employee-select"
                                            required>
                                            <option></option>
                                            @foreach($employees as $employee)
                                                <option value="{{ $employee->id }}">{{ $employee->first_name }}
                                                    {{ $employee->last_name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <div class="employee-details mt-2 text-dark small" style='display:none;'></div>
                                    </div>

                                    <label class="form-label custom-page-title">Weekdays Schedule</label>
                                    <table class="table" style="border-collapse: separate; border-spacing: 0;">
                                        <thead class="">
                                            <tr>
                                                <th class="rounded-top-start">
                                                    <div class="d-flex gap-2">
                                                        <input type="checkbox"
                                                            class="form-check-input select-all-days ms-2">
                                                        <span>All Days</span>
                                                    </div>
                                                </th>
                                                <th>Start Time</th>
                                                <th>End Time</th>
                                                <th>Timezone</th>
                                                <th>Total Hours</th>
                                                <th class="text-center rounded-top-end">
                                                    <input type="checkbox" class="form-check-input select-all-repeat">
                                                    Repeat
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day)
                                                <tr>
                                                    <td>
                                                        <div class="form-check">
                                                            <input type="checkbox" class="form-check-input enable-day me-2"
                                                                name="employees[0][weekdays][{{ $day }}][enabled]" value="1"
                                                                id="input-0-{{ $day }}">

                                                            <label for="input-0-{{ $day }}"
                                                                class="form-check-label">{{ $day }}</label>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <input type="time" class="form-control form-control-sm start-time"
                                                            name="employees[0][weekdays][{{ $day }}][start]">
                                                    </td>
                                                    <td>
                                                        <input type="time" class="form-control form-control-sm end-time"
                                                            name="employees[0][weekdays][{{ $day }}][end]">
                                                    </td>
                                                    <td>
                                                        <select name="employees[0][weekdays][{{ $day }}][timezone]"
                                                            class="form-control form-control-sm timezone-select">
                                                            <option value="">Select timezone</option>
                                                            @foreach (timezone_identifiers_list() as $tz)
                                                                <option value="{{ $tz }}">{{ $tz }}</option>
                                                            @endforeach
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm total-hours"
                                                            name="employees[0][weekdays][{{ $day }}][total_hours]" readonly>
                                                    </td>
                                                    <td class="text-center">
                                                        <input type="checkbox" class="form-check-input repeat-day"
                                                            name="employees[0][weekdays][{{ $day }}][repeat]" value="1">
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                    <div class="row justify-content-end mt-2 mb-3">
                                        <div class="col-md-4">
                                            <label for="per_hour_rate">Per Hour Rate:</label>
                                            <input type="text" name="employees[0][per_hour_rate]" class="form-control"
                                                required>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <div class="mb-3 text-end">
                                <button type="button" class="btn cstm-btn-link-2" id="addEmployeeBtn">+ Add
                                    Employee</button>
                                <button type="submit" class="btn cstm-btn-link text-white">Assign Employees</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function () {
            let employeeIndex = $('.employee-section').length;

            function initEmployeeSelect(section) {
                let $select = section.find('.employee-select');

                $select.select2({
                    placeholder: "Select an Employee",
                    allowClear: true,
                    width: '100%'
                });

                $select.off('change').on('change', function () {
                    let employeeId = $(this).val();
                    let detailsDiv = $(this).closest('.mb-3').find('.employee-details');

                    if (employeeId) {
                        $.ajax({
                            url: `/admin/assign-employee/${employeeId}/details`,
                            type: 'GET',
                            success: function (employee) {
                                detailsDiv.show().html(`
                                    <strong>${employee.first_name} ${employee.last_name}</strong><br>
                                    ${employee.email ?? '-'}
                                `);
                            },
                            error: function () {
                                detailsDiv.hide().html('');
                            }
                        });
                    } else {
                        detailsDiv.hide().html('');
                    }
                });
            }

            function resetSectionFields(section, index) {
                section.find('input, select, textarea').each(function () {
                    let $el = $(this);

                    if ($el.attr('name')) {
                        $el.attr('name', $el.attr('name').replace(/employees\[\d+\]/g, `employees[${index}]`));
                    }

                    if ($el.is(':checkbox')) {
                        $el.prop('checked', false);
                    } else {
                        $el.val('');
                    }
                });

                section.find('.employee-details').hide().html('');

                section.find('.enable-day').each(function () {
                    let day = $(this).closest('tr').find('.form-check-label').text().trim();
                    let newId = `input-${index}-${day}`;
                    $(this).attr('id', newId);
                    $(this).siblings('label').attr('for', newId);
                });
            }

            function buildNewSection(index) {
                let $first = $('.employee-section').first();
                let $clone = $first.clone(false, false);

                // remove select2 generated HTML from clone
                $clone.find('span.select2').remove();

                // restore original select visibility/state
                $clone.find('.employee-select').each(function () {
                    $(this)
                        .removeClass('select2-hidden-accessible')
                        .removeAttr('data-select2-id')
                        .removeAttr('tabindex')
                        .removeAttr('aria-hidden');
                });

                $clone.find('option').each(function () {
                    $(this).removeAttr('data-select2-id');
                });

                resetSectionFields($clone, index);

                return $clone;
            }

            initEmployeeSelect($('.employee-section').first());

            $('#addEmployeeBtn').on('click', function () {
                let $newSection = buildNewSection(employeeIndex);
                $('#employeeSections').append($newSection);
                initEmployeeSelect($newSection);
                employeeIndex++;
            });

            $(document).on('change', '.select-all-days', function () {
                $(this).closest('table').find('.enable-day').prop('checked', $(this).is(':checked'));
            });

            $(document).on('change', '.select-all-repeat', function () {
                $(this).closest('table').find('.repeat-day').prop('checked', $(this).is(':checked'));
            });

            $(document).on('change', '.start-time, .end-time', function () {
                let row = $(this).closest('tr');
                let start = row.find('.start-time').val();
                let end = row.find('.end-time').val();

                if (start && end) {
                    let startTime = new Date(`1970-01-01T${start}:00`);
                    let endTime = new Date(`1970-01-01T${end}:00`);
                    let diff = (endTime - startTime) / (1000 * 60 * 60);

                    if (diff < 0) diff += 24;

                    row.find('.total-hours').val(diff.toFixed(2));
                } else {
                    row.find('.total-hours').val('');
                }
            });
        });
    </script>
@endsection