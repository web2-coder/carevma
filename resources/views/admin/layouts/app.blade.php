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
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('Care_VMA-favicon.png') }}?v=20261026-admin" />
    <link rel="shortcut icon" type="image/png" href="{{ asset('Care_VMA-favicon.png') }}?v=20261026-admin" />
    <link rel="apple-touch-icon" href="{{ asset('Care_VMA-favicon.png') }}?v=20261026-admin" />
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


    <!-- Vendors CSS -->

    <link rel="stylesheet" href="/assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <!-- endbuild -->

    <link rel="stylesheet" href="/assets/vendor/libs/datatables-bs5/datatables.bootstrap5.css" />
    <link rel="stylesheet" href="/assets/vendor/libs/datatables-responsive-bs5/responsive.bootstrap5.css" />
    <link rel="stylesheet" href="/assets/vendor/libs/datatables-buttons-bs5/buttons.bootstrap5.css" />
    <link rel="stylesheet" href="/assets/vendor/libs/@form-validation/form-validation.css" />


    <script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
    <!-- Page CSS -->

    <!-- Helpers -->
    <script src="/assets/vendor/js/helpers.js"></script>
    <!--! Template customizer & Theme config files MUST be included after core stylesheets and helpers.js in the <head> section -->
    <script src="/assets/vendor/js/template-customizer.js"></script>

    <!--? Config:  Mandatory theme config file contain global vars & default theme options, Set your preferred theme option in this file.  -->

    <script src="/assets/js/config.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

<!-- pdfmake library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>

<!-- pdfmake virtual file system with fonts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

 
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

    .loader {
  width: 25px;
  aspect-ratio: 1;
  border-radius: 50%;
  background: #000;
  box-shadow: 0 0 0 0 #0004;
  animation: l2 1.5s infinite linear;
  position: relative;
}
.loader:before,
.loader:after {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: inherit;
  box-shadow: 0 0 0 0 #0004;
  animation: inherit;
  animation-delay: -0.5s;
}
.loader:after {
  animation-delay: -1s;
}
@keyframes l2 {
    100% {box-shadow: 0 0 0 40px #0000}
}

    #preloader {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.774); 
    backdrop-filter: blur(4px); 
    -webkit-backdrop-filter: blur(8px);
    z-index: 9999;
    display: flex;
    flex-direction: column; 
    align-items: center;
    justify-content: center;
    transition: opacity 0.9s ease, visibility 0.9s ease;
}
#loader-2{
        left: 10px;
    position: relative;
}
/* Loader Animation */
#loader-2 span {
    display: inline-block;
    width: 13px;
    height: 13px;
    border-radius: 100%;
    background-color: #0E3180;
    margin-right: 5px;
    opacity: 0;
}

#loader-2 span:nth-child(1) {
    animation: opacitychange 1s ease-in-out infinite;
}

#loader-2 span:nth-child(2) {
    animation: opacitychange 1s ease-in-out 0.33s infinite;
}

#loader-2 span:nth-child(3) {
    animation: opacitychange 1s ease-in-out 0.66s infinite;
}

@keyframes opacitychange {
    0%, 100% {
        opacity: 0;
    }
    60% {
        opacity: 1;
    }
}

    </style>

</head>
<body>

<!-- <div id="preloader">
<div class="loader"></div>
</div> -->

    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        @include('admin.layouts.sidebar')
            <div class="layout-page">
                @include('admin.layouts.nav')
                <div class="content-wrapper">
                <!-- Content -->
                @yield('admin_content')
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
    <script src="/assets/vendor/libs/dropzone/dropzone.js"></script>
    <script src="/assets/js/forms-file-upload.js"></script>

        <!-- Main JS -->

        <script src="/assets/js/main.js"></script>

    <script>
        // Hide Preloader 2s after page fully loads
        window.addEventListener("load", function() {
            setTimeout(function() {
                document.getElementById("preloader").style.display = "none";
                document.getElementById("main-content").style.display = "block";
            },2000); // 2000ms = 2 seconds
        });
    </script>

    @stack('scripts')
    <!-- Page JS -->
</body>
</html>