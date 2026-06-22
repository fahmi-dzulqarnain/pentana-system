<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatererMealOption extends Model
{
    use HasFactory;

    protected $fillable = ['caterer_meal_id', 'meal_option_id', 'price'];

    protected $casts = ['price' => 'decimal:2'];

    public function catererMeal(): BelongsTo
    {
        return $this->belongsTo(CatererMeal::class);
    }

    public function mealOption(): BelongsTo
    {
        return $this->belongsTo(MealOption::class);
    }

    public function getEffectivePriceAttribute(): float
    {
        return (float) ($this->price ?? $this->catererMeal->price);
    }
}
