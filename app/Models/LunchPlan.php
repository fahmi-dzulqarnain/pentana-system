<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LunchPlan extends Model
{
    protected $fillable = ['date', 'menu', 'notes'];

    protected $casts = [
        'date' => 'date',
    ];
}
