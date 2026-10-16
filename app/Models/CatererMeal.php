<?php

namespace App\Models;

use App\Models\Contracts\UsedByLunchDays;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatererMeal extends Model implements UsedByLunchDays
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

    /** Lunch days that serve this meal as one of their menus. */
    public function lunchDaysCount(): int
    {
        return Lunch::whereHas('menus', fn ($menus) => $menus->where('caterer_meal_id', $this->id))->count();
    }
}
