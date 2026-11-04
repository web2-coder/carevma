<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalarySlip extends Model
{
    protected $fillable = [
        'employee_id', 'period_start', 'period_end', 'currency', 'base_amount',
        'bonus_amount', 'deduction_amount', 'net_amount', 'status', 'notes',
        'generated_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'base_amount' => 'decimal:2',
        'bonus_amount' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function generator() { return $this->belongsTo(User::class, 'generated_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
