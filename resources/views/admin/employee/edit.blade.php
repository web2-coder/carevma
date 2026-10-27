@extends('admin.layouts.app')
@section('admin_content')

  <div class="container-xxl flex-grow-1 container-p-y">

    <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>
      <div class="col-md-5 custom-title-col">
        <h4 class='mb-0 custom-page-title'>Employee Profile - {{ $employee->first_name }}</h4>
        <p>you can update employee details.</p>
      </div>
      <div class="col-md-5 justify-content-end d-flex align-items-center gap-3">
        <a href="{{ route('add.employee') }}" class='btn cstm-btn-link-2 text-white'>Add</a>
        <a href="{{ route('all.employees') }}" class='btn cstm-btn-link text-white'>Employees</a>
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
    <div class="row d-flex justify-content-center form-row">
      <div class="col-md-10">
        <div class="nav-align-left">
          <ul class="nav nav-pills me-4" role="tablist">
            <li class="nav-item">
              <button type="button" class="nav-link active" role="tab" data-bs-toggle="tab"
                data-bs-target="#navs-pills-left-basic" aria-controls="navs-pills-left-basic" aria-selected="true">
                Basic
              </button>
            </li>
            <li class="nav-item">
              <button type="button" class="nav-link" role="tab" data-bs-toggle="tab"
                data-bs-target="#navs-pills-left-work" aria-controls="navs-pills-left-work" aria-selected="false">
                Work
              </button>
            </li>
            <li class="nav-item">
              <button type="button" class="nav-link" role="tab" data-bs-toggle="tab"
                data-bs-target="#navs-pills-left-hierarchy" aria-controls="navs-pills-left-hierarchy"
                aria-selected="false">
                Hierarchy
              </button>
            </li>
            <li class="nav-item">
              <button type="button" class="nav-link" role="tab" data-bs-toggle="tab"
                data-bs-target="#navs-pills-left-personal" aria-controls="navs-pills-left-personal" aria-selected="false">
                Personal
              </button>
            </li>
            <li class="nav-item">
              <button type="button" class="nav-link" role="tab" data-bs-toggle="tab"
                data-bs-target="#navs-pills-left-documents" aria-controls="navs-pills-left-documents"
                aria-selected="false">
                Documents
              </button>
            </li>
            <li class="nav-item">
              <button type="button" class="nav-link" role="tab" data-bs-toggle="tab"
                data-bs-target="#navs-pills-left-timezone" aria-controls="navs-pills-left-timezone" aria-selected="false">
                Timezone
              </button>
            </li>
          </ul>
          <form action="{{ route('update.employee', $employee->id) }}" method="POST" enctype="multipart/form-data"
            class="w-100">
            @csrf
            @method('PUT')
            <div class="tab-content">
              <div class="tab-pane fade show active" id="navs-pills-left-basic" role="tabpanel">
                <div class="mb-2">
                  <input type="hidden" name='employee_id' value='{{ $employee->id }}'>
                  <label class="form-label" for="multicol-first-name">First Name</label>
                  <input type="text" id="multicol-first-name" value='{{ $employee->first_name }}' name='first_name'
                    class="form-control @error('first_name') is-invalid @enderror" placeholder="John" />
                </div>
                <div class="mb-2">
                  <label class="form-label" for="multicol-last-name">Last Name</label>
                  <input type="text" id="multicol-last-name" value='{{ $employee->last_name }}' name='last_name'
                    class="form-control @error('last_name') is-invalid @enderror" placeholder="Doe" />
                </div>
                <div class="mb-2">
                  <label class="form-label" for="multicol-email">Email</label>
                  <input type="text" id="multicol-email" class="form-control @error('email') is-invalid @enderror"
                    name='email' value='{{ $employee->email }}' aria-label="john.doe"
                    aria-describedby="multicol-email2" />
                </div>
              </div>
              <div class="tab-pane fade" id="navs-pills-left-work" role="tabpanel">
                <div class="row mb-3">
                  <div class="col-md-6">
                    <label class="form-label" for="department">Department</label>
                    <select id="department" name='department'
                      class="select form-select @error('department') is-invalid @enderror">
                      <option>Select Department</option>
                      @foreach($departments as $department)
                        <option value="{{ $department->id }}" {{ old('department', $employee->department_id) == $department->id ? 'selected' : '' }}>{{ $department->name }}</option>
                      @endforeach
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="role">Role</label>
                    <select id="role" name='role' class="select form-select @error('role') is-invalid @enderror">
                      <option>Select Role</option>
                      @foreach($roles as $role)
                        <option value="{{ $role->id }}" {{ old('role', $employee->role_id) == $role->id ? 'selected' : '' }}>
                          {{ $role->name }}
                        </option>
                      @endforeach
                    </select>
                  </div>
                </div>
                <div class="row mb-3">
                  <div class="col-md-6">
                    <label class="form-label" for="employee_type">Employee Type</label>
                    <select id="employee_type" name='employee_type'
                      class="select form-select @error('employee_type') is-invalid @enderror">
                      <option>Select Employee Type</option>
                      @foreach($employmentTypes as $employmentType)
                        <option value="{{ $employmentType->id }}" {{ old('employee_type', $employee->employment_type_id) == $employmentType->id ? 'selected' : '' }}>
                          {{ $employmentType->name }}
                        </option>
                      @endforeach
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="designation">Designation</label>
                    <select id="designation" name='designation'
                      class="select form-select @error('designation') is-invalid @enderror">
                      <option>Select Designation</option>
                      @foreach($designations as $designation)
                        <option value="{{ $designation->id }}" {{ old('designation', $employee->designation_id) == $designation->id ? 'selected' : '' }}>{{ $designation->name }}
                        </option>
                      @endforeach
                    </select>
                  </div>
                </div>
                <div class="row mb-3">
                  <div class="col-md-6">
                    <label class="form-label" for="shift_type">Shift Type</label>
                    <select id="shift_type" name='shift_type'
                      class="select form-select @error('shift_type') is-invalid @enderror">
                      <option>Select Shift Type</option>
                      @foreach($shiftTypes as $shiftType)
                        <option value="{{ $shiftType->id }}" {{ old('shift_type', $employee->shift_type_id) == $shiftType->id ? 'selected' : '' }}>{{ $shiftType->name }}</option>
                      @endforeach
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="employee_status">Employee Status</label>
                    <select id="employee_status" name='employee_status'
                      class="select form-select @error('eployee_status') is-invalid @enderror">
                      <option disabled>Select Employee Status</option>
                      @foreach($employeeStatuses as $status)
                        <option value="{{ $status->id }}" {{ old('employee_status', $employee->employee_status_id) == $status->id ? 'selected' : '' }}>{{ $status->name }}</option>
                      @endforeach
                    </select>
                  </div>
                </div>
                <div class="row mb-3">
                  <div class="col-md-6">
                    <label class="form-label" for="salary_pkr">Salary In PKR</label>
                    <input type="text" id="salary_pkr" value='{{ $employee->salary_pkr }}' name='salary_pkr'
                      class="form-control @error('salary_pkr') is-invalid @enderror" placeholder="500" />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="salary_usd">Salary In USD</label>
                    <input type="text" value='{{ $employee->salary_usd }}' id="salary_usd" name='salary_usd'
                      class="form-control @error('salary_usd') is-invalid @enderror" placeholder="500" />
                  </div>
                </div>
                <div class="row mb-3">
                  <div class="col-md-6">
                    <label class="form-label" for="source_of_hire">Source of Hire</label>
                    <select id="source_of_hire" name='source_of_hire'
                      class="select form-select @error('source_of_hire') is-invalid @enderror">
                      <option value="seo" selected>SEO</option>
                      <option value="sales">Sales</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="joining_date">Date of Joining</label>
                    <input type="date" value='{{ $employee->date_of_joining }}' name='joining_date' id='joining_date'
                      class="form-control @error('joining_date') is-invalid @enderror" placeholder="YYYY-MM-DD" />
                  </div>
                </div>
                <div class="row mb-3">
                  <div class="col-md-6">
                    <label class="form-label" for="regularisation_date">Date of Regularisation</label>
                    <input type="date" value='{{ $employee->date_of_regularisation }}' name='regularisation_date'
                      id='regularisation_date' class="form-control" placeholder="YYYY-MM-DD" />
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="current_expertise">Current Expertise</label>
                    <select id="current_expertise" name='current_expertise' class="select form-select">
                      <option disabled>Select Expertise</option>
                      @foreach($expertises as $expertise)
                        <option value="{{ $expertise->id }}" {{ old('current_expertise', $employee->expertise_id) == $expertise->id ? 'selected' : '' }}>{{ $expertise->name }}</option>
                      @endforeach
                    </select>
                  </div>
                </div>
                <div class="row">
                  <div class="col-md-6">
                    <label class="form-label" for="multicol-birthdate">Break Allowed(Hrs)</label>
                    <input type="number" name="breaks" class="form-control" value='{{ $employee->break_allowed_hours }}'
                      placeholder="0.00" step="0.01" min="0" />
                  </div>
                </div>
              </div>
              <div class="tab-pane fade" id="navs-pills-left-hierarchy" role="tabpanel">
                <div class="mb-3">
                  <label class="form-label" for="reporting_manager">Reporting Manager</label>
                  <select id="reporting_manager" name='reporting_manager' class="select form-select">
                    <option disabled>Select Reporting Manager</option>
                    @foreach($reportingManagers as $manager)
                      <option value='{{ $manager->id }}' {{ old('reporting_manager', $employee->reporting_manager_id) == $manager->id ? 'selected' : '' }}>{{$manager->name}}</option>
                    @endforeach
                  </select>
                </div>
              </div>
              <div class="tab-pane fade" id="navs-pills-left-personal" role="tabpanel">
                <div class="mb-3">
                  <label class="form-label" for="gender">Gender</label>
                  <select id="gender" name='gender' class="select form-select">
                    <option disabled>Select Gender</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="other">Other</option>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label" for="marital_status">Marital Status</label>
                  <select id="marital_status" name='marital_status' class="select form-select">
                    <option value="single">Single</option>
                    <option value="married">Married</option>
                  </select>
                </div>
                <div class="mb-3 select2-primary">
                  <label class="form-label" for="multicol-birthdate">Age</label>
                  <input type="number" class="form-control" placeholder="20" value='{{ $employee->age }}' name='age' />
                </div>
                <div class="mb-3">
                  <label class="form-label" for="multicol-birthdate">Birth Date</label>
                  <input type="date" class="form-control" placeholder="YYYY-MM-DD" name='birth_date'
                    value='{{ $employee->date_of_birth }}' />
                </div>
                <div class="col-md-12">
                  <label class='form-label' for="">About Me Notes</label>
                  <textarea class="form-control" id="collapsible-notes" rows="2" name='notes'
                    placeholder="write additional notes">{{ $employee->about_me_notes }}</textarea>
                </div>
              </div>
              <div class="tab-pane fade" id="navs-pills-left-documents" role="tabpanel">
                <div class="mb-3">
                  <form action="/upload" class="dropzone needsclick dz-clickable" id="dropzone-multi">
                    <div class="dz-message needsclick">
                      Drop files here or click to upload
                      <span class="note needsclick">you can add multiple documents here.</span>
                    </div>
                    <div class="fallback">
                      <input name="file" type="file" />
                    </div>
                  </form>
                  <br>
                  @php
                    $docs = $employee->upload_documents;

                    // If it's string, decode properly
                    if (is_string($docs)) {
                      $docs = trim($docs, '"');   // remove extra quotes
                      $docs = json_decode($docs, true);
                    }
                  @endphp

                  @if(is_array($docs))
                    @foreach($docs as $doc)
                      <a href="{{ asset('public/' . $doc) }}" target="_blank">
                            View Document
                        </a>
                      <br>
                    @endforeach
                  @endif
                </div>
              </div>
              <div class="tab-pane fade" id="navs-pills-left-timezone" role="tabpanel">
                <div class="col-12">
                  <label for="timezone" class="form-label">Change Timezone</label>
                  <select name="timezone" id="timezone" class="form-control">
                    @foreach(timezone_identifiers_list() as $tz)
                      <option value="{{ $tz }}" {{ old('timezone', $employee->timezone) == $tz ? 'selected' : '' }}>
                        {{ $tz }}
                      </option>
                    @endforeach
                  </select>
                </div>
              </div>
              <div class="mt-4 d-block">
                <button type="submit" class="cstm-btn-link btn text-white me-4">Submit</button>
                <button type="reset" class="cstm-btn-link-2 btn">Cancel</button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>




@endsection