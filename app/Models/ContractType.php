<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractType extends Model
{
    protected $fillable = [
        'name',
    ];

    /**
     * A contract type can have many clients.
     */
    public function clients()
    {
        return $this->hasMany(Client::class);
    }
}
