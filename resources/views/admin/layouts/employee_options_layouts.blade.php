<ul class="nav nav-pills me-4" role="tablist">
        <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('departments.index') ? 'active' : '' }}" href="{{ route('departments.index') }}"            
        >Department</a
        >
        <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('roles.index') ? 'active' : '' }}" href="{{ route('roles.index') }}"            
        >Roles</a
        >
        <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('employment-types.index') ? 'active' : '' }}" href="{{ route('employment-types.index') }}"            
        >Employee Types</a
        >
        <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('shift-types.index') ? 'active' : '' }}" href="{{ route('shift-types.index') }}"            
        >Shift Types</a
        >
        <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('designations.index') ? 'active' : '' }}" href="{{ route('designations.index') }}"            
        >Designation</a
        >
        <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('employee-statuses.index') ? 'active' : '' }}" href="{{ route('employee-statuses.index') }}"            
        >Employee Status</a
        >
        <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('expertises.index') ? 'active' : '' }}" href="{{ route('expertises.index') }}"            
        >Expertise</a
        >
        <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('reporting-managers.index') ? 'active' : '' }}" href="{{ route('reporting-managers.index') }}"            
        >Reporting Managers</a
        >
    </ul>
