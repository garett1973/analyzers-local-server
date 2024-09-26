<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class Test extends Model
{
    use HasFactory;

    protected $fillable = [
        'test_id',
        'analyzer_id',
        'lab_id',
        'description',
    ];
}
