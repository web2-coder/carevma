<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = [
        'name',
    ];

    /**
     * A service can have many clients.
     */
    public function clients()
    {
        return $this->hasMany(Client::class);
    }
}
