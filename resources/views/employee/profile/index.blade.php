@extends('employee.layouts.app')
@section('employee_content')

<style>
    button.remove-doc {
        position: absolute;
        right: -9px;
        top: -9px;
        border-radius: 50px;
        width: 20px;
        height: 20px;
        background: red;
        border: 0;
        color: white;
        font-size: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
</style>

<div class="container-xxl flex-grow-1 container-p-y">
    <div class="row d-flex align-items-center justify-content-center mb-3">
        @php
            use Illuminate\Support\Facades\Auth;
            use Illuminate\Support\Facades\Storage;
            $employee = \App\Models\Employee::where('user_id', Auth::id())->first();
        @endphp

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        <div class="col-md-10">
            @if ($employee && !$employee->profile_completed)
                <div class="alert alert-warning d-flex justify-content-between align-items-center" role="alert"
                    style="z-index: 1050;">
                    <div>
                        ⚠️ <strong>Complete Your Profile:</strong> You must complete your profile before using the
                        system.
                    </div>
                </div>
            @endif
        </div>
    </div>
    <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>
        <div class="col-md-5 custom-title-col">
            <h4 class='mb-0 custom-page-title'>My Profile</h4>
            <p>you can update your details.</p>
        </div>
        <div class="col-md-5 justify-content-end d-flex align-items-center gap-3">

        </div>

        <div class="col-md-10 mt-5">
            {{-- Inline AJAX message will appear here --}}
            <div id="ajax-message" class="alert alert-success d-none" role="alert"></div>

            @if (session('success'))
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
                            data-bs-target="#navs-pills-left-basic" aria-controls="navs-pills-left-basic"
                            aria-selected="true">
                            Basic
                        </button>
                    </li>
                    <li class="nav-item">
                        <button type="button" class="nav-link" role="tab" data-bs-toggle="tab"
                            data-bs-target="#navs-pills-left-personal" aria-controls="navs-pills-left-personal"
                            aria-selected="false">
                            Personal
                        </button>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/employee/documents">Shared Documents</a>
                    </li>
                    <li class="nav-item">
                        <button type="button" class="nav-link" role="tab" data-bs-toggle="tab"
                            data-bs-target="#navs-pills-left-timezone" aria-controls="navs-pills-left-timezone"
                            aria-selected="false">
                            Timezone
                        </button>
                    </li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="navs-pills-left-basic" role="tabpanel">
                        <div class="d-flex align-items-center gap-3">
                            {{-- Fix profile picture URL --}}
                        @php
    $profilePicUrl = $employee->profile_picture
        ? (str_starts_with($employee->profile_picture, 'http')
            ? $employee->profile_picture
            : (str_starts_with($employee->profile_picture, '/storage/')
                ? asset('/public' . $employee->profile_picture)  // Added /public here
                : asset('/public/storage/' . $employee->profile_picture))) // Added /public/storage here
        : asset('assets/img/avatars/1.png');
@endphp
                            <img id="previewImage" src="{{ $profilePicUrl }}" alt=""
                                style='border-radius:8px;width:100px;height:100px;object-fit:cover;' class='mb-2'>
                            <div class="button-wrapper">
                                <label for="upload" class="btn btn-primary me-3 mb-4 waves-effect waves-light"
                                    tabindex="0">
                                    <span class="d-none d-sm-block text-white">Upload new photo</span>
                                    <i class="icon-base ti tabler-upload d-block d-sm-none"></i>
                                    <input type="file" name='profile_picture' id="upload"
                                        class="account-file-input" hidden="" accept="image/png, image/jpeg">
                                </label>
                                <button type="button" id='resetBtn'
                                    class="btn btn-label-secondary account-image-reset mb-4 waves-effect">
                                    <i class="icon-base ti tabler-reset d-block d-sm-none"></i>
                                    <span class="d-none d-sm-block">Reset</span>
                                </button>

                                <div>Allowed JPG, GIF or PNG. Max size of 800K</div>
                            </div>
                        </div>
                        <div class="mb-2">
                            <input type="hidden" name='employee_id' value='{{ $employee->id }}'>
                            <label class="form-label" for="multicol-first-name">First Name</label>
                            <input type="text" id="multicol-first-name" value='{{ $employee->first_name }}'
                                name='first_name' class="form-control @error('first_name') is-invalid @enderror"
                                placeholder="John" />
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="multicol-last-name">Last Name</label>
                            <input type="text" id="multicol-last-name" value='{{ $employee->last_name }}'
                                name='last_name' class="form-control @error('last_name') is-invalid @enderror"
                                placeholder="Doe" />
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="multicol-email">Email</label>
                            <input type="text" id="multicol-email"
                                class="form-control @error('email') is-invalid @enderror" name='email'
                                value='{{ $employee->email }}' aria-label="john.doe"
                                aria-describedby="multicol-email2" />
                        </div>
                    </div>


                    <div class="tab-pane fade" id="navs-pills-left-personal" role="tabpanel">
                        <div class="mb-3">
                            <select id="gender" name='gender' class="select form-select">
                                <option disabled>Select Gender</option>
                                <option value="male" {{ $employee->gender == 'male' ? 'selected' : '' }}>Male
                                </option>
                                <option value="female" {{ $employee->gender == 'female' ? 'selected' : '' }}>Female
                                </option>
                                <option value="other" {{ $employee->gender == 'other' ? 'selected' : '' }}>Other
                                </option>
                            </select>


                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="marital_status">Marital Status</label>
                            <select id="marital_status" name='marital_status' class='select form-select'>
                                <option value="single" {{ $employee->marital_status == 'single' ? 'selected' : '' }}>
                                    Single
                                </option>
                                <option value="married"
                                    {{ $employee->marital_status == 'married' ? 'selected' : '' }}>
                                    Married</option>
                            </select>
                        </div>
                        <div class="mb-3 select2-primary">
                            <label class="form-label" for="multicol-birthdate">Age</label>
                            <input type="number" class="form-control" placeholder="20"
                                value='{{ $employee->age }}' name='age' />
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="multicol-birthdate">Birth Date</label>
                            <input type="date" class="form-control" placeholder="YYYY-MM-DD" name='date_of_birth'
                                value='{{ $employee->date_of_birth }}' />
                        </div>
                        <div class="col-md-12">
                            <label class='form-label' for="">About Me Notes</label>
                            <textarea class="form-control" id="collapsible-notes" rows="2" name='about_me_notes'
                                placeholder="write additional notes">{{ $employee->about_me_notes }}</textarea>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="navs-pills-left-timezone" role="tabpanel">
                        <div class="col-12">
                            <label for="timezone" class="form-label">Change Timezone</label>
                            <select name="timezone" id="timezone" class="form-control" disabled>
                                @foreach (timezone_identifiers_list() as $tz)
                                    <option value="{{ $tz }}"
                                        {{ old('timezone', $employee->timezone) == $tz ? 'selected' : '' }}>
                                        {{ $tz }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const uploadInput = document.getElementById('upload');
    const previewImage = document.getElementById('previewImage');
    const resetBtn = document.getElementById('resetBtn');

    const defaultImage = previewImage.src;

    uploadInput.addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImage.src = e.target.result; // show new image
            }
            reader.readAsDataURL(file);
        }
    });

    resetBtn.addEventListener('click', function() {
        previewImage.src = defaultImage; // reset to default
        uploadInput.value = ""; // clear file input
    });
</script>

<script>
    // Helper to show inline messages (below heading)
    function showMessage(message, type = 'success') {
        let msgBox = $('#ajax-message');
        msgBox.removeClass('d-none alert-success alert-danger alert-warning')
              .addClass('alert alert-' + type)
              .text(message)
              .removeClass('d-none');
        console.log('Message shown:', message); // for debugging
        // Auto-hide after 3 seconds
        setTimeout(() => {
            msgBox.addClass('d-none');
        }, 3000);
    }

    $(document).ready(function() {

        // Optional: test message on page load to confirm the function works
        // showMessage('Page loaded - ready for updates', 'info');

        // Text, number, textarea, select
        $(document).on("change", "input:not([type=file]), textarea, select", function() {
            let field = $(this).attr("name");
            let value = $(this).val();

            $.ajax({
                url: "{{ route('employee.profile.update') }}",
                method: "POST",
                data: {
                    _token: "{{ csrf_token() }}",
                    field: field,
                    value: value
                },
                success: function(res) {
                    console.log("Saved:", res);
                    // Use server message if available, otherwise generic
                    let msg = res.message || 'Profile updated successfully.';
                    showMessage(msg);
                },
                error: function(err) {
                    console.error(err.responseJSON);
                    let msg = 'An error occurred while updating.';
                    if (err.responseJSON && err.responseJSON.message) {
                        msg = err.responseJSON.message;
                    } else if (err.responseJSON && err.responseJSON.errors) {
                        msg = Object.values(err.responseJSON.errors).flat().join(', ');
                    } else if (err.statusText) {
                        msg = err.statusText;
                    }
                    showMessage(msg, 'danger');
                }
            });
        });

        // File upload (profile picture)
        $(document).on("change", "input[type=file][name=profile_picture]", function() {
            let field = $(this).attr("name");
            let formData = new FormData();
            formData.append("_token", "{{ csrf_token() }}");
            formData.append("field", field);
            formData.append("value", this.files[0]);

            $.ajax({
                url: "{{ route('employee.profile.update') }}",
                method: "POST",
                data: formData,
                processData: false,
                contentType: false,
                success: function(res) {
                    if (res.value) {
                        $("#previewImage").attr("src", res.value);
                    }
                    console.log("Image uploaded:", res);
                    let msg = res.message || 'Profile picture updated.';
                    showMessage(msg);
                },
                error: function(err) {
                    console.error(err.responseJSON);
                    let msg = 'Image upload failed.';
                    if (err.responseJSON && err.responseJSON.message) {
                        msg = err.responseJSON.message;
                    } else if (err.responseJSON && err.responseJSON.errors) {
                        msg = Object.values(err.responseJSON.errors).flat().join(', ');
                    } else if (err.statusText) {
                        msg = err.statusText;
                    }
                    showMessage(msg, 'danger');
                }
            });
        });

    });
</script>


@endsection