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
        'total_hours',
        'total_amount',
        'status',
        'auto_generated',
        'generated_at',
        'edited_by',
        'edited_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'auto_generated' => 'boolean',
        'generated_at' => 'datetime',
        'edited_at' => 'datetime',
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
