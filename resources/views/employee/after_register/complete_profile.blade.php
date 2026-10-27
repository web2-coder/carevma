@extends('layouts.app')

@section('content')

    <div class="authentication-wrapper authentication-cover authentication-bg">

        <!-- /Logo -->
        <div class="authentication-inner row">
            <!-- Left Text -->
            <div class="d-none d-lg-flex col-lg-4 align-items-center justify-content-center p-5 position-relative auth-multisteps-bg-height"
                style="background-image:url('/people.webp')">
            </div>
            <!-- /Left Text -->

            <!--  Multi Steps Registration -->
            <div class="d-flex col-lg-8 align-items-center justify-content-center authentication-bg p-5">
                <div class="w-px-700">
                        <div class="col-md-12 mt-5">
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
                    <div id="multiStepsValidation" class="bs-stepper border-none shadow-none mt-5">
                        <div class="bs-stepper-header border-none pt-12 px-0">
                            <div class="step" data-target="#accountDetailsValidation">
                                <button type="button" class="step-trigger">
                                    <span class="bs-stepper-circle"><i
                                            class="icon-base ti tabler-file-analytics icon-md"></i></span>
                                    <span class="bs-stepper-label">
                                        <span class="bs-stepper-title">Profile Information</span>
                                        <span class="bs-stepper-subtitle">Enter Information</span>
                                    </span>
                                </button>
                            </div>
                            <div class="line">
                                <i class="icon-base ti tabler-chevron-right"></i>
                            </div>
                            <div class="step" data-target="#personalInfoValidation">
                                <button type="button" class="step-trigger">
                                    <span class="bs-stepper-circle"><i class="icon-base ti tabler-user icon-md"></i></span>
                                    <span class="bs-stepper-label">
                                        <span class="bs-stepper-title">Personal Information</span>
                                        <span class="bs-stepper-subtitle">Enter Information</span>
                                    </span>
                                </button>
                            </div>
                            <div class="line">
                                <i class="icon-base ti tabler-chevron-right"></i>
                            </div>
                            <div class="step" data-target="#billingLinksValidation">
                                <button type="button" class="step-trigger">
                                    <span class="bs-stepper-circle"><i
                                            class="icon-base ti tabler-credit-card icon-md"></i></span>
                                    <span class="bs-stepper-label">
                                        <span class="bs-stepper-title">Password</span>
                                        <span class="bs-stepper-subtitle">Set Password</span>
                                    </span>
                                </button>
                            </div>
                          
                        </div>
                        <div class="bs-stepper-content px-0">
                            <form id="multiStepsForm" method="POST" action="{{ route('employee.profile.update') }}" enctype="multipart/form-data">
                                @csrf
                                <!-- Account Details -->
                                <div id="accountDetailsValidation" class="content">
                                    <div class="row g-6">
                                        <div class="d-flex align-items-center gap-3">
                                            <img id="previewImage" src="{{ asset('assets/img/avatars/1.png') }}" alt=""
                                                style='border-radius:8px;width:100px;height:100px;object-fit:cover;'
                                                class='mb-2'>
                                            <div class="button-wrapper">
                                                <label for="upload"
                                                    class="btn btn-primary me-3 mb-4 waves-effect waves-light" tabindex="0">
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

                                                <div>Allowed JPG/JPEG/PNG. Max size 800 KB</div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label" for="multicol-first-name">First Name</label>
                                            <input type="text" id="multicol-first-name" name='first_name'
                                                class="form-control @error('first_name') is-invalid @enderror"
                                                placeholder="John" value="{{ $employee->first_name }}" />
                                                
                                                <input type="hidden" value="{{ $employee->id }}" name="employee_id">
                                                                                                
                                               
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label" for="multicol-last-name">Last Name</label>
                                            <input type="text" id="multicol-last-name" name='last_name'
                                                class="form-control @error('last_name') is-invalid @enderror"
                                                placeholder="Doe" value="{{ $employee->last_name }}" />
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label" for="multicol-email">Email</label>
                                            <input type="text" id="multicol-email"
                                                class="form-control @error('email') is-invalid @enderror" name='email'
                                                aria-label="john.doe" placeholder="example@gmail.com" value="{{ $employee->email }}"
                                                aria-describedby="multicol-email2" />
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label" for="multicol-phone">Phone</label>
                                            <input type="text" id="multicol-phone" name="phone"
                                                class="form-control @error('phone') is-invalid @enderror"
                                                placeholder="+1 555 000 0000" value="{{ old('phone', $employee->phone ?? '') }}" />
                                        </div>
                                        <div class="col-12 mt-2 d-flex justify-content-end">
                                            <button type="button" class="btn btn-primary btn-next waves-effect waves-light"><span
                                                    class="align-middle d-sm-inline-block d-none me-sm-1 me-0">Next</span>
                                                <i class="icon-base ti tabler-arrow-right icon-xs"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <!-- Personal Info -->
                                <div id="personalInfoValidation" class="content">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" for="gender">Gender</label>
                                            <select id="gender" name='gender' class="select form-select @error('gender') is-invalid @enderror">
                                                <option disabled>Select Gender</option>
                                                <option value="male">Male</option>
                                                <option value="female">Female</option>
                                                <option value="other">Other</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" for="marital_status">Marital Status</label>
                                            <select id="marital_status" name='marital_status' class="select form-select @error('marital_status') is-invalid @enderror">
                                                <option value="single">Single</option>
                                                <option value="married">Married</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3 select2-primary">
                                            <label class="form-label" for="multicol-birthdate">Age</label>
                                            <input type="number" class="form-control @error('age') is-invalid @enderror" value="{{ old('age', $employee->age ?? '') }}"  placeholder="20" name='age' />
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" for="multicol-birthdate">Birth Date</label>
                                            <input type="date" class="form-control @error('date_of_birth') is-invalid @enderror" value="{{ old('date_of_birth', $employee->date_of_birth ?? '') }}"  placeholder="YYYY-MM-DD"
                                                name='date_of_birth' />
                                        </div>
                                        <div class="col-md-12">
                                            <label class='form-label' for="">About Me Notes</label>
                                            <textarea class="form-control @error('notes') is-invalid @enderror" id="collapsible-notes" rows="2" name='about_me_notes'
                                                placeholder="write additional notes"></textarea>
                                        </div>
                                       
                                            <div class="col-md-12">
                                                <input name="upload_documents[]" class="@error('upload_documents') is-invalid @enderror" type="file" multiple />
                                            </div>
                                       
                                        <div class="col-12 mt-2 d-flex justify-content-between">
                                            <button type="button" class="btn btn-label-secondary btn-prev waves-effect">
                                                <i class="icon-base ti tabler-arrow-left icon-xs me-sm-2 me-0"></i>
                                                <span class="align-middle d-sm-inline-block d-none">Previous</span>
                                            </button>
                                            <button type="button" class="btn btn-primary btn-next waves-effect waves-light"><span
                                                    class="align-middle d-sm-inline-block d-none me-sm-1 me-0">Next</span>
                                                <i class="icon-base ti tabler-arrow-right icon-xs"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <!-- Billing Links -->
                                <div id="billingLinksValidation" class="content">
                                  

                                    <div class="row mb-3">
                                        <label for="password" class="col-md-4 col-form-label text-md-end">{{ __('Password') }}</label>

                                        <div class="col-md-6">
                                            <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" autocomplete="new-password">

                                            @error('password')
                                                <span class="invalid-feedback" role="alert">
                                                    <strong>{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="row mb-3">
                                        <label for="password-confirm" class="col-md-4 col-form-label text-md-end">{{ __('Confirm Password') }}</label>

                                        <div class="col-md-6">
                                            <input id="password-confirm" type="password" class="form-control" name="password_confirmation" autocomplete="new-password">
                                        </div>
                                    </div>

                                    <div class="col-12 mt-2 d-flex justify-content-between">
                                            <button type="button" class="btn btn-label-secondary btn-prev waves-effect">
                                                    <i class="icon-base ti tabler-arrow-left icon-xs me-sm-2 me-0"></i>
                                                    <span class="align-middle d-sm-inline-block d-none">Previous</span>
                                                </button>

                                           <button id="final-submit" type="button" class="btn btn-primary waves-effect waves-light">
    Submit
</button>
                                        </div>
                                </div>

                               
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <!-- / Multi Steps Registration -->
        </div>
    </div>

<script>
$(document).ready(function () {

    // Preview profile picture before upload
    $(document).on("change", "input[type=file][name=profile_picture]", function () {
        let file = this.files[0];
        if (file) {
            let reader = new FileReader();
            reader.onload = function (e) {
                $("#previewImage").attr("src", e.target.result);
            };
            reader.readAsDataURL(file);
        }
    });
});

</script>
<script>
document.addEventListener("DOMContentLoaded", function () {

    // FINAL SUBMIT BUTTON MUST SUBMIT THE FORM
    const finalSubmit = document.querySelector("#final-submit");

    if (finalSubmit) {
        finalSubmit.addEventListener("click", function (e) {
            e.preventDefault(); // stop stepper JS
            document.getElementById("multiStepsForm").submit(); // force real form submit
        });
    }

});
</script>



@endsection