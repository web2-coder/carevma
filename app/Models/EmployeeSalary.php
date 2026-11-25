<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSalary extends Model
{
    protected $casts = [
        'pdf_snapshot' => 'array',
    ];

    protected $fillable = [
        'employee_id',
        'client_id',
        'invoice_id',
        'total_hours',
        'salary_amount',
        'period_start',
        'period_end',
        'total_late',
        'total_overtime',
        'pdf_snapshot',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
