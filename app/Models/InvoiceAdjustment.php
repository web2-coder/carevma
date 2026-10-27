<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceAdjustment extends Model
{
    protected $fillable = [
        'invoice_id',
        'employee_id',
        'direction',
        'category',
        'description',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function getSignedAmountAttribute(): float
    {
        $amount = (float) $this->amount;
        return $this->direction === 'deduction' ? -$amount : $amount;
    }
}
