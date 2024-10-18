<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Result extends Model
{
    use HasFactory;

    protected $fillable = [
        'barcode',
        'analyte_id',
        'analyte_name',
        'result',
        'unit',
        'reference_range',
        'original_string',
        'created_at',
        'updated_at',
    ];
}
