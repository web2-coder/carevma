<nav class="layout-navbar container-xxl navbar-detached navbar navbar-expand-xl align-items-center bg-navbar-theme"
    id="layout-navbar">

@php
use Illuminate\Support\Facades\Auth;

$employee = \App\Models\Employee::where('user_id', Auth::id())->first();

/* Profile Image Same Logic As Profile Page */
$avatarUrl = $employee && $employee->profile_picture
    ? (str_starts_with($employee->profile_picture, 'http')
        ? $employee->profile_picture
        : (str_starts_with($employee->profile_picture, '/storage/')
            ? asset('/public' . $employee->profile_picture)
            : asset('/public/storage/' . $employee->profile_picture)))
    : asset('assets/img/avatars/1.png');

$defaultAvatar = asset('assets/img/avatars/1.png');

/* Name */
$displayName = $employee
    ? trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? ''))
    : Auth::user()->name;

/* Role */
$displayRole = $employee->role->name ?? 'Employee';

/* Timezone */
$timezone = $employee->timezone ?? config('app.timezone');

@endphp


<div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
    <a class="nav-item nav-link px-0 me-xl-6" href="javascript:void(0)">
        <i class="icon-base ti tabler-menu-2 icon-md"></i>
    </a>
</div>

<div class="navbar-nav-right d-flex align-items-center justify-content-end" id="navbar-collapse">

<ul class="navbar-nav flex-row align-items-center ms-md-auto">

<li>
<span id="live-datetime" class="badge bg-info"></span>
</li>


<!-- User Dropdown -->

<li class="nav-item navbar-dropdown dropdown-user dropdown mx-3">

<a class="nav-link dropdown-toggle hide-arrow p-0" href="javascript:void(0);" data-bs-toggle="dropdown">

<div class="avatar avatar-online">

<img src="{{ $avatarUrl }}"
onerror="this.onerror=null; this.src='{{ $defaultAvatar }}';"
style="object-fit:cover;"
class="rounded-circle"/>

</div>

</a>


<ul class="dropdown-menu dropdown-menu-end">

<li>
<a class="dropdown-item mt-0">
<div class="d-flex align-items-center">

<div class="flex-shrink-0 me-2">
<div class="avatar avatar-online">

<img src="{{ $avatarUrl }}"
onerror="this.onerror=null; this.src='{{ $defaultAvatar }}';"
class="rounded-circle"/>

</div>
</div>

<div class="flex-grow-1">
<h6 class="mb-0">{{ $displayName }}</h6>
<small class="text-body-secondary">{{ $displayRole }}</small>
</div>

</div>
</a>
</li>


<li>
<div class="dropdown-divider my-1 mx-n2"></div>
</li>


<li>
<a class="dropdown-item" href="{{ route('employee.profile.edit') }}">
<i class="icon-base ti tabler-user me-3 icon-md"></i>
<span class="align-middle">My Profile</span>
</a>
</li>


<li>
<a class="dropdown-item" href="{{ route('employee.setting') }}">
<i class="icon-base ti tabler-settings me-3 icon-md"></i>
<span class="align-middle">Settings</span>
</a>
</li>


<li>
<div class="dropdown-divider my-1 mx-n2"></div>
</li>


<li>
<div class="d-grid px-2 pt-2 pb-1">

<a class="btn btn-sm btn-danger d-flex"
href="{{ route('logout') }}"
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

</ul>

</div>

</nav>


<script>

function updateDateTime(){

const tz = "{{ $timezone ?? 'UTC' }}";

const now = new Date();

const options = {

weekday:'short',
year:'numeric',
month:'short',
day:'numeric',
hour:'2-digit',
minute:'2-digit',
second:'2-digit',
timeZone:tz

};

document.getElementById('live-datetime').textContent =
now.toLocaleString('en-US', options);

}

updateDateTime();
setInterval(updateDateTime,1000);

</script>