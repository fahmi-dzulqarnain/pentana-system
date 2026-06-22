<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Meal extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'base_price', 'image'];

    protected $casts = ['base_price' => 'decimal:2'];

    public function options(): BelongsToMany
    {
        return $this->belongsToMany(MealOption::class);
    }
}
