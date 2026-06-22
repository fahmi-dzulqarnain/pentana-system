<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $fillable = ['title', 'description', 'starts_at', 'location'];

    protected $casts = [
        'starts_at' => 'datetime',
    ];
}
