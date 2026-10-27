    @php
      use Illuminate\Support\Facades\Auth;
      $employee = \App\Models\Employee::where('user_id', Auth::id())->first();
    @endphp

  @if($employee && !$employee->profile_completed && !request()->routeIs('employee.profile.edit'))
    <div id="profile-lock-overlay" 
         class="position-absolute top-0 start-0 w-100 h-100 d-flex justify-content-center align-items-center"
         style="z-index: 99999;background: rgb(0 0 0 / 0%); backdrop-filter: blur(3px);">
        <div class="card shadow-lg p-4 text-center" style="max-width: 500px;">
            <h4 class="mb-3">⚠️ Complete Your Profile</h4>
            <p class="mb-4">You must complete your profile before using the system.</p>
            <a href="{{ route('employee.profile.edit') }}" class="btn btn-primary">
                Complete Profile Now
            </a>
        </div>
    </div>
@endif
