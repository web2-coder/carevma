<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BirthdayWish extends Model
{
    protected $fillable = [
        'sender_user_id',
        'recipient_employee_id',
        'birthday_date',
    ];

    protected $casts = [
        'birthday_date' => 'date',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function recipientEmployee()
    {
        return $this->belongsTo(Employee::class, 'recipient_employee_id');
    }
}
