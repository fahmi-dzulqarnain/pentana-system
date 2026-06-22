<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lunch extends Model
{
    use HasFactory;

    protected $table = 'lunches';

    protected $fillable = ['date', 'caterer_id', 'caterer_meal_id', 'deadline', 'status'];

    protected $casts = ['date' => 'date', 'deadline' => 'datetime'];

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
        return $this->hasMany(LunchOption::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(LunchResponse::class);
    }

    /** Members may vote only while a lunch is published and the deadline instant has not yet passed. */
    public function isOpenForVoting(): bool
    {
        return $this->status === 'published'
            && $this->deadline !== null
            && now()->lte($this->deadline);
    }

    public function attendingCount(): int
    {
        return $this->responses->whereNotNull('meal_option_id')->count();
    }

    public function notAttendingCount(): int
    {
        return $this->responses->whereNull('meal_option_id')->count();
    }

    /** @return array<int, int> meal_option_id => count */
    public function optionCounts(): array
    {
        return $this->responses
            ->whereNotNull('meal_option_id')
            ->countBy('meal_option_id')
            ->map(fn ($count) => (int) $count)
            ->all();
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
