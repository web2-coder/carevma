@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="../../assets/vendor/css/pages/page-auth.css" />

<div class="authentication-wrapper authentication-cover">
      <!-- /Logo -->
      <div class="authentication-inner row m-0">
        <!-- /Left Text -->
        <div class="d-none d-xl-flex col-xl-8 p-0">
          <div class="auth-cover-bg d-flex justify-content-center align-items-center">
            <img
              src="../../assets/img/illustrations/auth-register-illustration-light.png"
              alt="auth-register-cover"
              class="my-5 auth-illustration"
              data-app-light-img="illustrations/auth-register-illustration-light.png"
              data-app-dark-img="illustrations/auth-register-illustration-dark.png" />
            <img
              src="../../assets/img/illustrations/bg-shape-image-light.png"
              alt="auth-register-cover"
              class="platform-bg"
              data-app-light-img="illustrations/bg-shape-image-light.png"
              data-app-dark-img="illustrations/bg-shape-image-dark.png" />
          </div>
        </div>
        <!-- /Left Text -->

        <!-- Register -->
        <div class="d-flex col-12 col-xl-4 align-items-center authentication-bg p-sm-12 p-6">
          <div class="w-px-400 mx-auto mt-12 pt-5">
            <h4 class="mb-1">Create an Account</h4>
            <p class="mb-6">Make your portal account registration!</p>

            <form id="formAuthentication" class="mb-6" action="{{ route('register') }}" method="POST">
                @csrf
              <div class="mb-6 form-control-validation">
                <label for="name" class="form-label">Full Name</label>
                <input
                  type="text"
                  class="form-control @error('name') is-invalid @enderror"
                  id="name"
                  name="name"
                  placeholder="Enter your Name"
                  autofocus />
              </div>
             <div class="row">
             <div class="mb-6 form-control-validation col-md-6">
                <label for="email" class="form-label">Email</label>
                <input type="text" class="form-control @error('email') is-invalid @enderror" id="email" name="email" placeholder="Enter your Email" />
              </div>
              <div class="mb-6 form-control-validation col-md-6">
                <label for="phone" class="form-label">Phone</label>
                <input type="text" class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" placeholder="Enter your Phone" />
              </div>
             </div>
              <div class="row">
                <div class="mb-4 form-password-toggle form-control-validation col-md-6">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-group input-group-merge">
                    <input
                        type="password"
                        id="password"
                        class="form-control"
                        name="password"
                        placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                        aria-describedby="password" />
                    <span class="input-group-text cursor-pointer"><i class="icon-base ti tabler-eye-off"></i></span>
                    </div>
                </div>
                <div class="mb-4 form-password-toggle form-control-validation col-md-6">
                    <label class="form-label" for="password">Confirm Password</label>
                    <div class="input-group input-group-merge">
                    <input
                        type="password"
                        id="password-confirmed"
                        class="form-control"
                        name="password_confirmation"
                        placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                        aria-describedby="password" 
                        required autocomplete="new-password" />
                    <span class="input-group-text cursor-pointer"><i class="icon-base ti tabler-eye-off"></i></span>
                    </div>
                </div>
              </div>
              <div class="mb-6 mt-4">
                <div class="form-check mb-8 ms-2">
                  <input class="form-check-input" type="checkbox" id="terms-conditions" name="terms" />
                  <label class="form-check-label" for="terms-conditions">
                    I agree to
                    <a href="javascript:void(0);">privacy policy & terms</a>
                  </label>
                </div>
              </div>
              <button class="btn btn-primary d-grid w-100" type='submit'>Sign up</button>
            </form>

            <p class="text-center">
              <span>Already have an account?</span>
              <a href="{{ route('login') }}">
                <span>Sign in instead</span>
              </a>
            </p>

            <div class="divider my-6">
              <div class="divider-text">or</div>
            </div>

            <div class="d-flex justify-content-center">
              <a href="javascript:;" class="btn btn-icon rounded-circle btn-text-facebook me-1_5">
                <i class="icon-base ti tabler-brand-facebook-filled icon-20px"></i>
              </a>

              <a href="javascript:;" class="btn btn-icon rounded-circle btn-text-twitter me-1_5">
                <i class="icon-base ti tabler-brand-twitter-filled icon-20px"></i>
              </a>

              <a href="javascript:;" class="btn btn-icon rounded-circle btn-text-github me-1_5">
                <i class="icon-base ti tabler-brand-github-filled icon-20px"></i>
              </a>

              <a href="javascript:;" class="btn btn-icon rounded-circle btn-text-google-plus">
                <i class="icon-base ti tabler-brand-google-filled icon-20px"></i>
              </a>
            </div>
          </div>
        </div>
        <!-- /Register -->
      </div>
    </div>
@endsection


