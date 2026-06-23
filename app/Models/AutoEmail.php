<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoEmail extends Model
{
    protected $fillable = ['subject', 'body', 'send_at', 'status', 'type', 'is_active'];

    protected $casts = [
        'send_at' => 'datetime',
        'is_active' => 'boolean',
    ];
}
