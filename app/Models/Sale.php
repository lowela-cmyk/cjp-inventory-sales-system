<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use SoftDeletes;

    protected $table = 'sales';

    protected $fillable = [
        'sale_code',
        'sales_order_number',
        'delivery_type',
        'customer_id',
        'sale_date',
        'payment_method',
        'payment_terms',
        'status',
        'delivery_status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'deleted_at' => 'datetime',
        ];
    }
}
