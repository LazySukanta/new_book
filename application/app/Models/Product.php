<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'retail_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'reorder_level' => 'decimal:3',
            'tax_inclusive' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
