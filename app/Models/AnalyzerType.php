<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnalyzerType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'group_id'
    ];
}
