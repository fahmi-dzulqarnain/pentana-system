<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityRegistration extends Model
{
    use HasFactory;

    protected $fillable = ['activity_id', 'user_id', 'status', 'registered_at', 'attended'];

    protected $casts = [
        'registered_at' => 'datetime',
        'attended' => 'boolean',
        'activity_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
