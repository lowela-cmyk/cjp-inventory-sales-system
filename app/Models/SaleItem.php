<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    protected $table = 'sale_items';

    protected $fillable = [
        'sale_id',
        'fuel_type_id',
        'purchase_item_id',
        'f1_purchase_item_id',
        'quantity_liters',
        'unit_price',
        'f1_price',
        'f1_unit_price',
        'f1_percentage',
        'main_quantity_liters',
        'f1_quantity_liters',
        'line_total',
        'fulfilled_quantity_liters',
    ];

    protected function casts(): array
    {
        return [
            'quantity_liters' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'f1_price' => 'decimal:2',
            'f1_unit_price' => 'decimal:2',
            'f1_percentage' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }
}
