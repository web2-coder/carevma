<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per sign-in attempt, sign-out or successful write request, recorded by OperationalActivityNotifier. */
class ActivityLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'actor_name', 'actor_email', 'actor_role', 'action', 'module', 'method', 'path',
        'status_code', 'description', 'subject_user_id', 'subject_name', 'ip_address', 'user_agent',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }
}
