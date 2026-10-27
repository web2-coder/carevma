<?php

namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $fillable = [
        'employee_id','client_id','schedule_id','date','timezone_snapshot','shift_start_snapshot','shift_end_snapshot','clock_in','clock_out',
        'break_limit','break_taken','total_minutes','overtime','is_late','late_minutes',
        'overtime_status','approved_overtime_minutes','overtime_amount','overtime_approved_by','overtime_approved_at'
    ];

    protected $casts = [
        'date' => 'date',
        'overtime_approved_at' => 'datetime',
        'shift_start_snapshot' => 'datetime',
        'shift_end_snapshot' => 'datetime',
    ];

    /**
     * Attendance clock values are persisted in MySQL as timezone-less UTC values.
     * Always interpret the raw database value as UTC. This prevents Laravel's
     * application timezone (e.g. Asia/Karachi) from adding/subtracting five hours.
     */
    protected function clockIn(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->fromDatabaseUtc($value),
            set: fn ($value) => $this->toDatabaseUtc($value),
        );
    }

    protected function clockOut(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->fromDatabaseUtc($value),
            set: fn ($value) => $this->toDatabaseUtc($value),
        );
    }

    private function fromDatabaseUtc(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc();
        }

        try {
            return Carbon::createFromFormat($this->getDateFormat(), (string) $value, 'UTC')->utc();
        } catch (\Throwable) {
            return Carbon::parse((string) $value, 'UTC')->utc();
        }
    }

    private function toDatabaseUtc(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc()->format($this->getDateFormat());
        }

        return Carbon::parse((string) $value, 'UTC')->utc()->format($this->getDateFormat());
    }

    public function employee(){ return $this->belongsTo(Employee::class); }
    public function breaks(){ return $this->hasMany(EmployeeBreak::class); }
    public function client(){ return $this->belongsTo(Client::class); }
    public function schedule(){ return $this->belongsTo(EmployeeClientSchedule::class, 'schedule_id'); }
}
