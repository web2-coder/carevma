<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyFinanceEntry extends Model
{
    protected $fillable = [
        'entry_date',
        'type',
        'category',
        'amount',
        'description',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
