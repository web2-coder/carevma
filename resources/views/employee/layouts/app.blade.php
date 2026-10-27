<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />

      <title>{{ $global_setting->site_title ?? 'Carevma Health' }}</title>

    <meta name="description" content="" />

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('Care_VMA-favicon.png') }}" />

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&ampdisplay=swap"
      rel="stylesheet" />

    <link rel="stylesheet" href="/assets/vendor/fonts/iconify-icons.css" />
    <link rel="stylesheet" href="/assets/vendor/fonts/fontawesome.css" />
    <link rel="stylesheet" href="/assets/vendor/css/pages/cards-advance.css" />
    <link rel="stylesheet" href="/assets/vendor/libs/dropzone/dropzone.css" />

    
    <!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Bootstrap Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>





    <!-- Core CSS -->
    <!-- build:css assets/vendor/css/theme.css  -->

    <link rel="stylesheet" href="/assets/vendor/libs/node-waves/node-waves.css" />

    <link rel="stylesheet" href="/assets/vendor/css/core.css" />
    <link rel="stylesheet" href="/assets/css/demo.css" />
    <link rel="stylesheet" href="/assets/css/custom.css" />
    <link rel="stylesheet" href="/assets/vendor/libs/select2/select2.css" />
    <link rel="stylesheet" href="/assets/vendor/libs/bs-stepper/bs-stepper.css" />


    <!-- Vendors CSS -->

    <link rel="stylesheet" href="/assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <!-- endbuild -->

    <link rel="stylesheet" href="/assets/vendor/libs/datatables-bs5/datatables.bootstrap5.css" />
    <link rel="stylesheet" href="/assets/vendor/libs/datatables-responsive-bs5/responsive.bootstrap5.css" />
    <link rel="stylesheet" href="/assets/vendor/libs/datatables-buttons-bs5/buttons.bootstrap5.css" />
    <link rel="stylesheet" href="/assets/vendor/libs/@form-validation/form-validation.css" />

    <!-- Page CSS -->

    <!-- Helpers -->
    <script src="/assets/vendor/js/helpers.js"></script>
    <!--! Template customizer & Theme config files MUST be included after core stylesheets and helpers.js in the <head> section -->
    <script src="/assets/vendor/js/template-customizer.js"></script>

    <!--? Config:  Mandatory theme config file contain global vars & default theme options, Set your preferred theme option in this file.  -->

    <script src="/assets/js/config.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
 
  <style>
    h2#swal2-title {
    font-size: 15px !important;
    color: black
    font-weight: 700;
    }
    .swal2-popup{
      z-index: 999 !important;
    }
    .swal2-container.swal2-top-end.swal2-backdrop-show {
        width: 27rem !important;
    }
    .swal2-toast div:where(.swal2-html-container) {
        font-size: 13px !important;
        font-weight: 700;
        color: red;
        line-height: 1.5em;
    }
  </style>
   @php
      use Illuminate\Support\Facades\Auth;
      $employee = \App\Models\Employee::where('user_id', Auth::id())->first();
  @endphp

  @if($employee && !$employee->profile_completed)
    <style>
        .content-wrapper {
            overflow: hidden; /* Prevent scrolling */
        }
    </style>
  @endif

</head>
<body>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        @include('employee.layouts.sidebar')
            <div class="layout-page">
                @include('employee.layouts.nav')
                <div class="content-wrapper position-relative">
              
                <!-- Content -->
                @yield('employee_content')
                @include('employee.partials.profile-lock')
                </div>
            </div>
        </div>
    </div>


    <script src="/assets/vendor/libs/jquery/jquery.js"></script>
    <script src="/assets/vendor/libs/popper/popper.js"></script>
    <script src="/assets/vendor/js/bootstrap.js"></script>
    <script src="/assets/vendor/libs/node-waves/node-waves.js"></script>

    <script src="/assets/vendor/libs/@algolia/autocomplete-js.js"></script>

    <script src="/assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>

    <script src="/assets/vendor/libs/hammer/hammer.js"></script>

    <script src="/assets/vendor/libs/i18n/i18n.js"></script>

    <script src="/assets/vendor/js/menu.js"></script>

    <!-- endbuild -->

    <!-- Vendors JS -->
    <script src="/assets/vendor/libs/datatables-bs5/datatables-bootstrap5.js"></script>

    <script src="/assets/vendor/libs/@form-validation/popular.js"></script>
    <script src="/assets/vendor/libs/@form-validation/bootstrap5.js"></script>
    <script src="/assets/vendor/libs/@form-validation/auto-focus.js"></script>

    <script src="/assets/vendor/libs/cleave-zen/cleave-zen.js"></script>
    <script src="/assets/vendor/libs/moment/moment.js"></script>
    <script src="/assets/vendor/libs/flatpickr/flatpickr.js"></script>
    <script src="/assets/js/form-layouts.js"></script>
    <script src="/assets/vendor/libs/select2/select2.js"></script>
    <script src="/assets/js/form-layouts.js"></script>
    <script src="/assets/js/forms-file-upload.js"></script>
    <script src="/assets/vendor/libs/bs-stepper/bs-stepper.js"></script>
    <script src="/assets/js/form-wizard-numbered.js"></script>

        <!-- Main JS -->

        <script src="/assets/js/main.js"></script>


    @stack('scripts')
    <!-- Page JS -->
</body>
</html>
