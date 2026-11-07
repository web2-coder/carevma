<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'client_id',
        'invoice_number',
        'period_start',
        'period_end',
        'billing_cycle_month',
        'total_hours',
        'total_amount',
        'status',
        'auto_generated',
        'generated_at',
        'edited_by',
        'edited_at',
        'sent_at',
        'send_failed_at',
        'send_error',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'billing_cycle_month' => 'date',
        'auto_generated' => 'boolean',
        'generated_at' => 'datetime',
        'edited_at' => 'datetime',
        'sent_at' => 'datetime',
        'send_failed_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function salaries()
    {
        return $this->hasMany(EmployeeSalary::class);
    }

    public function adjustments()
    {
        return $this->hasMany(InvoiceAdjustment::class);
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
