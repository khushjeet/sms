<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GradingScheme extends Model
{
    use HasFactory;

    protected $fillable = [
        'board_id',
        'min_percentage',
        'max_percentage',
        'grade',
        'grade_point',
        'is_active',
    ];

    protected $casts = [
        'min_percentage' => 'decimal:2',
        'max_percentage' => 'decimal:2',
        'grade_point' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}

