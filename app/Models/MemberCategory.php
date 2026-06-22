<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MemberCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'default_fee',
        'description',
    ];

    protected $casts = [
        'default_fee' => 'decimal:2',
    ];

    /**
     * Get all users belonging to this category.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
