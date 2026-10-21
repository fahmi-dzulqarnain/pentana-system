<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LunchMenu extends Model
{
    use HasFactory;

    protected $fillable = ['lunch_id', 'caterer_id', 'caterer_meal_id', 'sort'];

    protected $casts = ['lunch_id' => 'integer', 'caterer_id' => 'integer', 'caterer_meal_id' => 'integer', 'sort' => 'integer'];

    public function lunch(): BelongsTo
    {
        return $this->belongsTo(Lunch::class);
    }

    public function caterer(): BelongsTo
    {
        return $this->belongsTo(Caterer::class);
    }

    public function catererMeal(): BelongsTo
    {
        return $this->belongsTo(CatererMeal::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(LunchOption::class)->orderBy('id');
    }

    public function label(): string
    {
        return $this->caterer?->name.' · '.$this->catererMeal?->name;
    }

    /** Planned cost: each enabled option's default_count × its effective price on this menu. */
    public function estimatedCost(): float
    {
        $prices = $this->catererMeal?->options->keyBy('meal_option_id') ?? collect();

        return round($this->options->where('enabled', true)->sum(
            fn (LunchOption $o) => $o->default_count * (float) ($prices->get($o->meal_option_id)?->effective_price ?? 0)
        ), 2);
    }
}
