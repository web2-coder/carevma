<nav class="layout-navbar container-xxl navbar-detached navbar navbar-expand-xl align-items-center bg-navbar-theme"
    id="layout-navbar">
    <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
        <a class="nav-item nav-link px-0 me-xl-6" href="javascript:void(0)">
            <i class="icon-base ti tabler-menu-2 icon-md"></i>
        </a>
    </div>

    <div class="navbar-nav-right d-flex align-items-center justify-content-end" id="navbar-collapse">
        <!-- Search -->
        <div class="navbar-nav align-items-center">
            <div class="nav-item navbar-search-wrapper px-md-0 px-2 mb-0">
            </div>
        </div>

        <!-- /Search -->

        <ul class="navbar-nav flex-row align-items-center ms-md-auto">

            @php
                use Illuminate\Support\Facades\Auth;
                $employee = \App\Models\Employee::where('user_id', Auth::id())->first();
                $timezone = $employee->timezone ?? config('app.timezone'); // fallback if not set
            @endphp

            <li>
                <span id="live-datetime" class="badge bg-info"></span>
            </li>
            <!-- Quick links  -->
       
            <!-- Quick links -->

            <!-- Notification -->
            {{-- <li class="nav-item dropdown-notifications navbar-dropdown dropdown me-3 me-xl-2">
                <a class="nav-link dropdown-toggle hide-arrow btn btn-icon btn-text-secondary rounded-pill"
                    href="javascript:void(0);" data-bs-toggle="dropdown" data-bs-auto-close="outside"
                    aria-expanded="false">
                    <span class="position-relative">
                        <i class="icon-base ti tabler-bell icon-22px text-heading"></i>
                        <span class="badge rounded-pill bg-danger badge-dot badge-notifications border"></span>
                    </span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end p-0">
                    <li class="dropdown-menu-header border-bottom">
                        <div class="dropdown-header d-flex align-items-center py-3">
                            <h6 class="mb-0 me-auto">Notification</h6>
                            <div class="d-flex align-items-center h6 mb-0">
                                <span class="badge bg-label-primary me-2">0 New</span>
                                <a href="javascript:void(0)" class="dropdown-notifications-all p-2 btn btn-icon"
                                    data-bs-toggle="tooltip" data-bs-placement="top" title="Mark all as read"><i
                                        class="icon-base ti tabler-mail-opened text-heading"></i></a>
                            </div>
                        </div>
                    </li>
                    <li class="dropdown-notifications-list scrollable-container">
                        <ul class="list-group text-center p-5 list-group-flush">
                           No Notifications
                        </ul>
                    </li>
                    <li class="border-top">
                        <div class="d-grid p-4">
                            <a class="btn btn-primary btn-sm d-flex" href="javascript:void(0);">
                                <small class="align-middle">View all notifications</small>
                            </a>
                        </div>
                    </li>
                </ul>
            </li> --}}
            <!--/ Notification -->

  @php
    $timezone = $client->timezone ?? config('app.timezone'); // $client controller se pass ho raha hai
@endphp

            <li class="nav-item navbar-dropdown dropdown-user dropdown mx-3">
                <a class="nav-link dropdown-toggle hide-arrow p-0" href="javascript:void(0);"
                    data-bs-toggle="dropdown">
                    <div class="avatar avatar-online">
                        <img src="{{ $client->profile_picture ?? asset('assets/img/avatars/1.png') }}" alt
                            style='object-fit:cover;' class="rounded-circle" />
                    </div>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <a class="dropdown-item mt-0 waves-effect">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0 me-2">
                                    <div class="avatar avatar-online">
                                        <img src="{{ $client->profile_picture ?? asset('assets/img/avatars/1.png') }}"
                                            alt="Profile" class="rounded-circle" style="object-fit:cover;" />
                                    </div>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-0">{{ $client->name ?? 'No Name' }}</h6>
                                    <small class="text-muted">{{ $client->email ?? 'No Email' }}</small>
                                </div>
                            </div>
                        </a>
                    </li>
                    <li>
                        <div class="dropdown-divider my-1 mx-n2"></div>
                    </li>
                    <li>
                  <a class="dropdown-item" href="{{ route('doctor.profile') }}">
    <i class="icon-base ti tabler-user me-3 icon-md"></i>
    <span class="align-middle">My Profile</span>
</a>

                    </li>
                    <li>
                        <div class="d-grid px-2 pt-2 pb-1">
                            <a class="btn btn-sm btn-danger d-flex" href="{{ route('logout') }}"
                                onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                                <small class="align-middle">Logout</small>
                                <i class="icon-base ti tabler-logout ms-2 icon-14px"></i>
                            </a>

                            <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                                @csrf
                            </form>
                        </div>
                    </li>
                </ul>
            </li>
            <!--/ User -->

            <!--/ User -->
        </ul>
    </div>
</nav>

<script>
    function updateDateTime() {
        const tz = "{{ $timezone }}"; // employee's timezone from DB
        const now = new Date();

        // Format with timezone
        const options = {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            timeZone: tz
        };

        document.getElementById('live-datetime').textContent =
            now.toLocaleString('en-US', options);
    }

    updateDateTime();
    setInterval(updateDateTime, 1000);
</script>
