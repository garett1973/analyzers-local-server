<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'c_order_id',
        'external_id',
        'order_barcode',
        'test_barcode',
        'order_record',
        'is_additional',
        'tests_count',
        'completed_tests_count',
        'is_completed',
    ];
}
