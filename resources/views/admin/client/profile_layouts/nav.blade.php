<ul class="nav nav-pills me-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('client.profile') ? 'active' : '' }}"
            href="{{ route('client.profile', $client->id) }}"><i class="icon-base ti tabler-user me-1_5"></i>
            Profile</a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('client.profile.timeozone') ? 'active' : '' }}"
            href="{{ route('client.profile.timeozone', $client->id) }}"><i class="icon-base ti tabler-clock me-1_5"></i>
            Timezone</a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('client.schedule') ? 'active' : '' }}"
            href="{{ route('client.schedule', $client->id) }}"><i class="icon-base ti tabler-calendar-week me-1_5"></i>
            Schedule</a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('client.assigned.va') ? 'active' : '' }}"
            href="{{ route('client.assigned.va', $client->id) }}"><i class="icon-base ti tabler-users me-1_5"></i>
            Assign VA</a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('daily.report') ? 'active' : '' }}"
            href="{{ route('daily.report', $client->id) }}"><i
                class="icon-base ti tabler-clock icon-sm me-1_5"></i>Daily Work Report</a>
    </li>
</ul>