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
              src="../../assets/img/illustrations/auth-login-illustration-light.png"
              alt="auth-login-cover"
              class="my-5 auth-illustration"
              data-app-light-img="illustrations/auth-login-illustration-light.png"
              data-app-dark-img="illustrations/auth-login-illustration-dark.png" />
            <img
              src="../../assets/img/illustrations/bg-shape-image-light.png"
              alt="auth-login-cover"
              class="platform-bg"
              data-app-light-img="illustrations/bg-shape-image-light.png"
              data-app-dark-img="illustrations/bg-shape-image-dark.png" />
          </div>
        </div>
        <!-- /Left Text -->

        <!-- Login -->
        <div class="d-flex col-12 col-xl-4 align-items-center authentication-bg p-sm-12 p-6">
          <div class="w-px-400 mx-auto mt-12 pt-5">
            <h4 class="mb-1">Welcome to Care VMA Health</h4>
            <p class="mb-6">Please sign-in to your account for updates</p>

            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0 p-0" style='list-style-type:none;'>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif


            <form id="formAuthentication" class="mb-6" method="POST" action="{{ route('login') }}">
                @csrf
              <div class="mb-6 form-control-validation">
                <label for="email" class="form-label">Email or Username</label>
                <input
                  type="text"
                  class="form-control @error('email') is-invalid @enderror"
                  id="email"
                  name="email"
                  placeholder="Enter your email or username"
                  value="{{ old('email') }}" required autocomplete="email"
                  autofocus />
                  @error('email')
                      <div class="text-danger mt-1 small">{{ $message }}</div>
                  @enderror
              </div>
              <div class="mb-6 form-password-toggle form-control-validation">
                <label class="form-label" for="password">Password</label>
                <div class="input-group input-group-merge">
                  <input
                    type="password"
                    id="password"
                    class="form-control @error('password') is-invalid @enderror"
                    name="password"
                    placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                    aria-describedby="password" />
                  <span class="input-group-text cursor-pointer"><i class="icon-base ti tabler-eye-off"></i></span>
                </div>
                @error('password')
                    <div class="text-danger mt-1 small">{{ $message }}</div>
                @enderror
              </div>
              <div class="my-8">
                <div class="d-flex justify-content-between">
                  <div class="form-check mb-0 ms-2">
                    <input class="form-check-input" type="checkbox" id="remember-me" />
                    <label class="form-check-label" for="remember-me"> Remember Me </label>
                  </div>
                  <a href="{{ route('password.request') }}">
                    <p class="mb-0">Forgot Password?</p>
                  </a>
                </div>
              </div>
              <button class="btn btn-primary d-grid w-100" type='submit'>Sign in</button>
            </form>

            <div class="divider my-6">
              <div class="divider-text">or</div>
            </div>

            <div class="mt-3">
                <a href="{{ route('google.login') }}" 
                  class="btn w-100 d-flex align-items-center justify-content-center" style='background:#ededed;border: 1px solid #dddada;'>
                    <img src="https://img.icons8.com/color/16/000000/google-logo.png" class="me-2"/>
                    Login with Google
                </a>
            </div>

          </div>
        </div>
        <!-- /Login -->
      </div>
    </div>

@endsection