<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Analyzer extends Model
{
    use HasFactory;

    protected $fillable = [
        'analyzer_id',
        'lab_id',
        'name',
        'model',
        'serial_number',
        'location',
        'local_ip',
        'is_oneway',
        'is_active',
        'type_id'
    ];
}

