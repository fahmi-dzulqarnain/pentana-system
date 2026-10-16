<?php

namespace App\Models;

use App\Models\Contracts\UsedByLunchDays;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caterer extends Model implements UsedByLunchDays
{
    use HasFactory;

    protected $fillable = ['name', 'contact_name', 'phone', 'email', 'notes'];

    public function meals(): HasMany
    {
        return $this->hasMany(CatererMeal::class);
    }

    /** Lunch days with a menu from this caterer, by caterer or by one of its meals. */
    public function lunchDaysCount(): int
    {
        return Lunch::whereHas('menus', fn ($menus) => $menus
            ->where('caterer_id', $this->id)
            ->orWhereIn('caterer_meal_id', $this->meals()->select('id'))
        )->count();
    }
}
