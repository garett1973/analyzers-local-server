<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Analyte extends Model
{
    use HasFactory;

    protected $fillable = [
        'lab_id',
        'analyzer_id',
        'test_id',
        'analyte_id',
        'name',
        'description',
    ];
}
