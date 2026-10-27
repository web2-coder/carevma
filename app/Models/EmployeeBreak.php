<?php

namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class EmployeeBreak extends Model
{
    protected $fillable = ['attendance_id', 'start_time', 'end_time', 'notes'];

    protected function startTime(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->fromDatabaseUtc($value),
            set: fn ($value) => $this->toDatabaseUtc($value),
        );
    }

    protected function endTime(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->fromDatabaseUtc($value),
            set: fn ($value) => $this->toDatabaseUtc($value),
        );
    }

    private function fromDatabaseUtc(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') return null;
        if ($value instanceof DateTimeInterface) return Carbon::instance($value)->utc();
        try {
            return Carbon::createFromFormat($this->getDateFormat(), (string) $value, 'UTC')->utc();
        } catch (\Throwable) {
            return Carbon::parse((string) $value, 'UTC')->utc();
        }
    }

    private function toDatabaseUtc(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if ($value instanceof DateTimeInterface) return Carbon::instance($value)->utc()->format($this->getDateFormat());
        return Carbon::parse((string) $value, 'UTC')->utc()->format($this->getDateFormat());
    }

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }
}
