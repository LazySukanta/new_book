<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $hidden = ['active_phone', 'walk_in_key'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_system' => 'boolean', 'credit_limit' => 'decimal:2'];
    }
}
