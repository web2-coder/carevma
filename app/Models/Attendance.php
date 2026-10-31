<?php

namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    /**
     * Fields that can be mass assigned.
     */
    protected $fillable = [
        'work_session_id',
        'employee_id',
        'client_id',
        'schedule_id',
        'date',
        'timezone_snapshot',
        'shift_start_snapshot',
        'shift_end_snapshot',
        'clock_in',
        'check_in_comment',
        'clock_out',
        'break_limit',
        'break_taken',
        'total_minutes',
        'overtime',
        'is_late',
        'late_minutes',
        'overtime_status',
        'approved_overtime_minutes',
        'overtime_amount',
        'overtime_approved_by',
        'overtime_approved_at',
    ];

    /**
     * Attribute casts.
     */
    protected $casts = [
        'date' => 'date',
        'overtime_approved_at' => 'datetime',
        'shift_start_snapshot' => 'datetime',
        'shift_end_snapshot' => 'datetime',
    ];

    /**
     * Attendance clock values are persisted in MySQL as timezone-less UTC values.
     *
     * Always interpret the raw database value as UTC.
     * This prevents Laravel's application timezone
     * (for example Asia/Karachi) from shifting attendance times.
     */
    protected function clockIn(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->fromDatabaseUtc($value),
            set: fn ($value) => $this->toDatabaseUtc($value),
        );
    }

    /**
     * Clock-out is also stored/retrieved as UTC.
     */
    protected function clockOut(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->fromDatabaseUtc($value),
            set: fn ($value) => $this->toDatabaseUtc($value),
        );
    }

    /**
     * Convert raw MySQL datetime value to UTC Carbon instance.
     */
    private function fromDatabaseUtc(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc();
        }

        try {
            return Carbon::createFromFormat(
                $this->getDateFormat(),
                (string) $value,
                'UTC'
            )->utc();
        } catch (\Throwable) {
            return Carbon::parse(
                (string) $value,
                'UTC'
            )->utc();
        }
    }

    /**
     * Convert supplied datetime to UTC MySQL format.
     */
    private function toDatabaseUtc(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)
                ->utc()
                ->format($this->getDateFormat());
        }

        return Carbon::parse(
            (string) $value,
            'UTC'
        )
            ->utc()
            ->format($this->getDateFormat());
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Employee / VA belonging to this attendance record.
     */
    public function workSession()
    {
        return $this->belongsTo(WorkSession::class, 'work_session_id');
    }

    public function employee()
    {
        return $this->belongsTo(
            Employee::class,
            'employee_id'
        );
    }

    /**
     * All break records belonging to this attendance session.
     */
    public function breaks()
    {
        return $this->hasMany(
            EmployeeBreak::class,
            'attendance_id'
        );
    }

    /**
     * Current open break for this attendance session.
     *
     * A break is considered open when end_time is NULL.
     *
     * IMPORTANT:
     * Do NOT use latestOfMany() here.
     *
     * latestOfMany() was generating an aggregate/join query
     * that caused:
     *
     * SQLSTATE[23000]:
     * Column 'attendance_id' in SELECT is ambiguous
     *
     * Qualifying the columns and using orderByDesc avoids
     * that SQL ambiguity while still returning the latest
     * currently-open break.
     */
    public function openBreak()
    {
        return $this->hasOne(
            EmployeeBreak::class,
            'attendance_id'
        )
            ->whereNull('employee_breaks.end_time')
            ->orderByDesc('employee_breaks.start_time');
    }

    /**
     * Client / Doctor attached to this attendance.
     */
    public function client()
    {
        return $this->belongsTo(
            Client::class,
            'client_id'
        );
    }

    /**
     * Schedule used for this attendance record.
     */
    public function schedule()
    {
        return $this->belongsTo(
            EmployeeClientSchedule::class,
            'schedule_id'
        );
    }
}