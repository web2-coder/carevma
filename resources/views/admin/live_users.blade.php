@extends('admin.layouts.app')

@section('admin_content')
<style>
    .pulse-icon {
        position: relative;
        display: inline-block;
    }
    .img-div{
        position: relative;
    }
    .img-div i {
        position: absolute;
        right: 2px;
        bottom: 11px;
    }
</style>

<div class="container flex-grow-1 container-p-y">
    <div class='row d-flex justify-content-center mt-5 align-items-center mb-5'>
        <div class="col-md-5 custom-title-col">
            <h4 class='mb-0 custom-page-title'>Today’s Attendance</h4>
            <p>You can see all employees live status details here.</p>
        </div>
        <div class="col-md-5 text-end">
            <a href="{{ route('all.employees') }}" class='btn cstm-btn-link text-white'>Employees</a>
        </div>

        <div class="col-md-10 mt-5">
            @if(session('success'))
                <div class="alert alert-solid-success d-flex align-items-center" role="alert">
                    <span class="alert-icon rounded">
                        <i class="icon-base ti tabler-check icon-md"></i>
                    </span>
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-solid-danger d-flex align-items-center" role="alert">
                    <span class="alert-icon rounded">
                        <i class="icon-base ti tabler-ban icon-md"></i>
                    </span>
                    {{ implode(' | ', $errors->all()) }}
                </div>
            @endif
        </div>
    </div>

    <div class="row d-flex align-items-center justify-content-center">
        <div class="col-md-10 mb-5">
            <!-- Live Users -->
            <div class="custom-card-body mb-3">
                <h5 style="font-weight:700;margin-bottom:30px !important;">Live Users</h5>
                <div id="liveUsersRow"></div>   <!-- removed flex classes -->
            </div>
            <!-- Checked Out Users -->
            <div class="custom-card-body mb-3">
                <h5 style="font-weight:700;margin-bottom:30px !important;">Checked Out Users</h5>
                <div id="checkedOutUsersRow"></div>
            </div>
            <!-- Offline Users -->
            <div class="custom-card-body mb-3">
                <h5 style="font-weight:700;margin-bottom:30px !important;">Offline Users</h5>
                <div id="offlineUsersRow"></div>
            </div>
            <!-- Late Users -->
            <div class="custom-card-body mb-3">
                <h5 style="font-weight:700;margin-bottom:30px !important;">Late Users</h5>
                <div id="lateUsersRow"></div>
            </div>
        </div>
    </div>
</div>

<!-- ✅ AJAX Script -->
<script>
    function buildCard(user, type) {
        let icon = 'ti ti-login text-success';
        let border = 'card-border-shadow-success';
        let label = 'Live';
        let pulseClass = 'pulse-icon';

        if (type === 'checkedOut') { 
            icon = 'ti ti-logout text-danger'; 
            border = 'card-border-shadow-danger'; 
            label = 'CheckOut'; 
            pulseClass = 'pulse-icon pulse-danger'; 
        }
        if (type === 'offline') { 
            icon = 'ti ti-user text-muted'; 
            border = 'card-border-shadow-mute'; 
            label = 'Offline'; 
            pulseClass = 'pulse-icon pulse-secondary'; 
        }
        if (type === 'late') { 
            icon = 'ti ti-clock text-warning'; 
            border = 'card-border-shadow-warning'; 
            label = 'Late'; 
            pulseClass = 'pulse-icon pulse-warning'; 
        }

        return `
            <div class="h-100" data-type="${type}">
                <div class="d-flex flex-column justify-content-center align-items-center text-center">
                    <div class='img-div'>
                        <i class="${icon} rounded icon-12px"></i>
                        <img src="${user.profile_picture 
        ? `/public/${user.profile_picture}` 
        : `/profile.png`}" 
alt="${user.full_name}" 
class="rounded-circle mb-2"
style="width:50px; height:50px; object-fit:cover;">
                    </div>
                    <h5 class="mb-1" style="line-height:1.1em;font-size:12px;font-weight:700;">${user.full_name}</h5>
                </div>
            </div>
        `;
    }

    async function refreshAttendance() {
        try {
            const res = await fetch("{{ route('live.users.stats') }}");
            if (!res.ok) return;
            const data = await res.json();

            const render = (arr, rowId, type) => {
                const row = document.getElementById(rowId);
                if (!row) return;
                if (arr.length === 0) {
                    row.innerHTML = `<div class="col-12"><p class="text-muted">No Users</p></div>`;
                    return;
                }
                let html = '<div class="row g-3">';  // grid with gutters
                arr.forEach(u => { 
                    html += `<div class="col-md-1 col-6">${buildCard(u, type)}</div>`;
                });
                html += '</div>';
                row.innerHTML = html;
            };

            render(data.liveUsers, 'liveUsersRow', 'live');
            render(data.checkedOutUsers, 'checkedOutUsersRow', 'checkedOut');
            render(data.offlineUsers, 'offlineUsersRow', 'offline');
            render(data.lateUsers, 'lateUsersRow', 'late');
        } catch (err) {
            console.error('Error fetching attendance data', err);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        refreshAttendance();
        setInterval(refreshAttendance, 10000);
    });
</script>
@endsection