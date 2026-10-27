<aside id="layout-menu" class="layout-menu menu-vertical menu">
          <div class="app-brand demo">
            <a href="{{ route('employee.dashboard') }}" class="app-brand-link">
              <span class="app-brand-logo demo">
                @if(!empty($global_setting->logo))
                <img src="{{ asset('Care_VMA.webp') }}" alt="Site Logo" style="height:auto;width:170px;">
              @else
                <img src="{{ asset('Care_VMA.webp') }}" alt="Default Logo" style="height:auto;width:170px;">
              @endif

              </span>
            </a>

            <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto">
              <i class="icon-base ti menu-toggle-icon d-none d-xl-block"></i>
              <i class="icon-base ti tabler-x d-block d-xl-none"></i>
            </a>
          </div>

          <div class="menu-inner-shadow"></div>

          <ul class="menu-inner py-1">

            <li class="menu-item">
              <a href="{{ route('employee.dashboard') }}" class="menu-link">
                <i class="menu-icon icon-base ti tabler-smart-home"></i>
                <div data-i18n="Home">Home</div>
              </a>
            </li>

            
            <li class="menu-item">
              <a href="{{ route('employee.profile.edit') }}" class="menu-link">
                <i class="menu-icon icon-base ti tabler-id"></i>
                <div data-i18n="My Profile">My Profile</div>
              </a>
            </li>

            <li class="menu-item">
              <a href="{{ route('my.attendance') }}" class="menu-link">
                <i class="menu-icon icon-base ti tabler-calendar-week"></i>
                <div data-i18n="My Attendance">My Attendance</div>
              </a>
            </li>

            <li class="menu-item">
              <a href="{{ route('employee.setting') }}" class="menu-link">
                <i class="icon-base ti tabler-settings me-3 icon-md"></i>
                <div data-i18n="Setting">Setting</div>
              </a>
            </li>

          </ul>
        </aside>