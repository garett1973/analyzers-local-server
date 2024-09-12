<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Result extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'barcode',
        'analyte_code',
        'lis_code',
        'result',
        'unit',
        'reference_range',
        'created_at',
        'updated_at',
    ];
}
