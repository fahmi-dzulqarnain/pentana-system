<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatererMeal extends Model
{
    use HasFactory;

    protected $fillable = ['caterer_id', 'meal_id', 'name', 'price', 'image'];

    protected $casts = ['price' => 'decimal:2'];

    public function caterer(): BelongsTo
    {
        return $this->belongsTo(Caterer::class);
    }

    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(CatererMealOption::class);
    }
}
