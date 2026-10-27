<?php

namespace App\Models;

use App\Support\MediaUrl;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable; // if employees need login
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Employee extends Model
{
    use HasFactory;

    // If employees should be authenticatable (with login), extend Authenticatable
    // If not, just extend Model
    // For now, I'll keep it simple with Model:
    // class Employee extends Model

    protected $fillable = [
        'user_id',
        'profile_picture',
        'first_name',
        'last_name',
        'phone',
        'email',
        'password',
        'date_of_birth',
        'age',
        'gender',
        'marital_status',
        'about_me_notes',
        'department_id',
        'role_id',
        'location',
        'timezone',
        'employment_type_id',
        'shift_type_id',
        'designation_id',
        'employee_status_id',
        'salary_pkr',
        'salary_usd',
        'source_of_hire',
        'upload_documents',
        'date_of_joining',
        'date_of_regularisation',
        'expertise_id',
        'break_allowed_hours',
        'reporting_manager_id',
        'profile_completed',
        'probation_end_date',
        'is_probation_email_sent'
    ];

    protected $hidden = [
        'password',
    ];
    protected $casts = [
        'upload_documents' => 'array',
        'date_of_birth' => 'date',
        'profile_completed' => 'boolean',
    ];

    public const REQUIRED_SELF_SERVICE_FIELDS = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'date_of_birth',
        'gender',
        'marital_status',
    ];


    public function getProfilePictureAttribute($value)
    {
        return MediaUrl::normalize($value);
    }

    // 🔹 Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function employmentType()
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function shiftType()
    {
        return $this->belongsTo(ShiftType::class);
    }

    public function designation()
    {
        return $this->belongsTo(Designation::class);
    }

    public function employeeStatus()
    {
        return $this->belongsTo(EmployeeStatus::class);
    }

    public function expertise()
    {
        return $this->belongsTo(Expertise::class);
    }

    public function reportingManager()
    {
        return $this->belongsTo(ReportingManager::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'employee_id');
    }

    // 🔹 Accessors
    public function getFullNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }
    public function clientSchedules()
    {
        return $this->hasMany(EmployeeClientSchedule::class, 'employee_id');
    }
    
    public function clients()
    {
        return $this->belongsToMany(Client::class, 'employee_client_schedules', 'employee_id', 'client_id')
                    ->withPivot(['weekday', 'start_time', 'end_time', 'no_of_hours', 'enabled', 'repeat']);
    }

    public function salaries()
    {
        return $this->hasMany(EmployeeSalary::class);
    }

    public function probationEndsOn(): ?Carbon
    {
        if (!$this->date_of_joining) {
            return null;
        }

        return Carbon::parse($this->date_of_joining)->addMonths(3);
    }

    public function probationReminderDate(): ?Carbon
    {
        return $this->probationEndsOn()?->copy()->subDays(7);
    }
public function clientRates()
{
    return $this->hasMany(ClientEmployeeRate::class, 'employee_id');
}




    public function isOnProbation()
    {
        return $this->employment_type_id != 1; // 1 = Permanent
    }

    public function missingRequiredProfileFields(): array
    {
        return collect(self::REQUIRED_SELF_SERVICE_FIELDS)
            ->filter(function (string $field) {
                $value = $this->getAttribute($field);

                if ($value instanceof \DateTimeInterface) {
                    return false;
                }

                return $value === null || trim((string) $value) === '';
            })
            ->values()
            ->all();
    }

    public function hasCompleteSelfServiceProfile(): bool
    {
        return $this->missingRequiredProfileFields() === [];
    }



    protected static function boot()
    {
        parent::boot();

        static::creating(function ($employee) {
            $lastEmployee = self::orderBy('id', 'desc')->first();
            $nextNumber = $lastEmployee ? ((int) filter_var($lastEmployee->employee_code, FILTER_SANITIZE_NUMBER_INT)) + 1 : 1;

            $employee->employee_code = 'CVMA' . $nextNumber;

            // Accounts created by Admin/HR must be completed by the VA after
            // accepting the invitation and choosing a password. This deliberately
            // overrides older creation code that marked a new VA as complete.
            $creatorRole = auth()->user()?->role;
            if (in_array($creatorRole, ['admin', 'superadmin', 'hr'], true)) {
                $employee->profile_completed = false;
            }
        });
    }
}
