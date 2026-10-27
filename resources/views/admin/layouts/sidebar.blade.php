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
          <li class='menu-item {{ request()->routeIs("admin.dashboard") ? "active" : "" }}'>
              <a
                  type="button"
                  data-bs-toggle="tooltip"
                  data-bs-placement="right"
                  class='menu-link custom-tooltip'
                  href="{{ route('admin.dashboard') }}"
                  title="Dashboard">
                  <i class="menu-icon icon-base ti tabler-home"></i>
              </a>
          </li>

          <li class='menu-item {{ request()->routeIs("live.users") ? "active" : "" }}'>
              <a
                type="button"
                data-bs-toggle="tooltip"
                data-bs-placement="right"
                class='menu-link custom-tooltip'
                href="{{ route('live.users') }}"
                title="Live Employees">
                <i class="menu-icon icon-base ti tabler-screen-share"></i>
              </a>
            </li>

          <li class='menu-item {{ request()->routeIs("all.employees") ? "active" : "" }}'>
              <a
                  type="button"
                  data-bs-toggle="tooltip"
                  data-bs-placement="right"
                  class='menu-link custom-tooltip'
                  href="{{ route('all.employees') }}"
                  title="Employees">
                  <i class="menu-icon icon-base ti tabler-users"></i>
              </a>
          </li>

          <li class='menu-item {{ request()->routeIs("admin.attendance") ? "active" : "" }}'>
              <a
                  type="button"
                  data-bs-toggle="tooltip"
                  data-bs-placement="right"
                  class='menu-link custom-tooltip'
                  href="{{ route('admin.attendance') }}"
                  title="Attendance">
                  <i class="menu-icon icon-base ti tabler-calendar-week"></i>
              </a>
          </li>

          <li class='menu-item {{ request()->routeIs("all.clients") ? "active" : "" }}'>
              <a
                  type="button"
                  data-bs-toggle="tooltip"
                  data-bs-placement="right"
                  class='menu-link custom-tooltip'
                  href="{{ route('all.clients') }}"
                  title="Clients">
                  <i class="menu-icon icon-base ti tabler-users"></i>
              </a>
          </li>

            <li class='menu-item'>
              <a
                type="button"
                data-bs-toggle="tooltip"
                data-bs-placement="right"
                class='menu-link custom-tooltip'
                href="{{ route('all.clients') }}"
                title="Client Setting">
                <i class="menu-icon icon-base ti tabler-settings"></i>
              </a>
            </li>

            <li class='menu-item {{ request()->routeIs("admin.invoices") ? "active" : "" }}'>
              <a
                type="button"
                data-bs-toggle="tooltip"
                data-bs-placement="right"
                class='menu-link custom-tooltip'
                href="{{ route('admin.invoices') }}"
                title="Invoices">
                <i class="menu-icon icon-base ti tabler-file-dollar"></i>
              </a>
            </li>






            <li class="menu-item">
              <a href="#" class="menu-link ">
                <i class="menu-icon icon-base ti tabler-user-square-rounded"></i>
                <div data-i18n="Clients">Clients</div>
              </a>
                <ul class="menu-sub">

                  <li class="menu-item">
                    <a href="{{ route('all.clients') }}" class="menu-link">
                      <div data-i18n="All Clients">All Clients</div>
                    </a>
                  </li>
                  <li class="menu-item">
                    <a href="{{ route('add.client') }}" class="menu-link">
                      <div data-i18n="Add New Client">Add New Client</div>
                    </a>
                  </li>
                </ul>
            </li>
          
          </ul>
        </aside>