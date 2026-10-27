<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientEmployeeRate extends Model
{
     protected $fillable = [
        'client_id',
        'employee_id',
        'per_hour_rate'
    ];
}
