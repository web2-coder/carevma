<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeClientSchedule extends Model
{
    protected $fillable = [
        'client_id',
        'employee_id',
        'weekday',
        'schedule_date',
        'start_time',
        'end_time',
        'timezone',
        'no_of_hours',
        'break_allowed_hours',
        'enabled',
        'repeat'
    ];

    protected $casts = [
        'schedule_date' => 'date',
        'break_allowed_hours' => 'decimal:2',
        'enabled' => 'boolean',
        'repeat' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
