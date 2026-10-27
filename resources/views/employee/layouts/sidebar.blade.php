<aside id="layout-menu" class="layout-menu menu-vertical menu">
  <div class="app-brand demo">
    <a href="/" class="app-brand-link">
      <span class="app-brand-logo demo">
        @if(!empty($global_setting->logo))
          <img src="{{ asset('Care_VMA-favicon.png') }}" alt="Site Logo" style="height:auto;width:30px;">
        @else
          <img src="{{ asset('Care_VMA-favicon.png') }}" alt="Default Logo" style="height:auto;width:30px;">
        @endif

      </span>
    </a>
  </div>

  <div class="menu-inner-shadow"></div>

  <ul class="menu-inner py-1">
    <li class='menu-item {{ request()->routeIs("employee.dashboard") ? "active" : "" }}'>
      <a type="button" data-bs-toggle="tooltip" data-bs-placement="right" class='menu-link custom-tooltip'
        href="{{ route('employee.dashboard') }}" title="Dashboard">
        <i class="menu-icon icon-base ti tabler-home"></i>
      </a>
    </li>

    <li class='menu-item {{ request()->routeIs("employee.schedule") ? "active" : "" }}'>
      <a type="button" data-bs-toggle="tooltip" data-bs-placement="right" class='menu-link custom-tooltip'
        href="{{ route('employee.schedule') }}" title="My Schedule">
        <i class="menu-icon icon-base ti tabler-calendar"></i>
      </a>
    </li>

    <li class='menu-item {{ request()->routeIs("employee.profile.edit") ? "active" : "" }}'>
      <a type="button" data-bs-toggle="tooltip" data-bs-placement="right" class='menu-link custom-tooltip'
        href="{{ route('employee.profile.edit') }}" title="My Profile">
        <i class="menu-icon icon-base ti tabler-user"></i>
      </a>
    </li>

    <li class='menu-item {{ request()->routeIs("my.attendance") ? "active" : "" }}'>
      <a type="button" data-bs-toggle="tooltip" data-bs-placement="right" class='menu-link custom-tooltip'
        href="{{ route('my.attendance') }}" title="Attendance">
        <i class="menu-icon icon-base ti tabler-calendar-week"></i>
      </a>
    </li>

    <li class='menu-item {{ request()->routeIs("employee.setting") ? "active" : "" }}'>
      <a type="button" data-bs-toggle="tooltip" data-bs-placement="right" class='menu-link custom-tooltip'
        href="{{ route('employee.setting') }}" title="Setting">
        <i class="menu-icon icon-base ti tabler-settings"></i>
      </a>
    </li>

  </ul>
</aside>