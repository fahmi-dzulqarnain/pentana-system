<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lunch extends Model
{
    use HasFactory;

    protected $table = 'lunches';

    protected $fillable = ['date', 'deadline', 'status', 'vote_nudge_sent_at'];

    protected $casts = ['date' => 'date', 'deadline' => 'datetime', 'vote_nudge_sent_at' => 'datetime'];

    public function menus(): HasMany
    {
        return $this->hasMany(LunchMenu::class)->orderBy('sort')->orderBy('id');
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

    /**
     * Unlike optionCounts(), this keeps the same meal option on two menus apart.
     *
     * @return array<int, int> lunch_option_id => count
     */
    public function optionCountsByLunchOption(): array
    {
        return $this->responses
            ->whereNotNull('lunch_option_id')
            ->countBy('lunch_option_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function hasSeveralMenus(): bool
    {
        return $this->menus->count() > 1;
    }

    /** Past the deadline, or closed by an admin: picks can no longer change. */
    public function isLocked(): bool
    {
        return $this->status === 'closed' || ($this->deadline !== null && now()->gt($this->deadline));
    }

    /**
     * The committee can no longer change the day's menus, option toggles or counts: ordering
     * has closed (closed, or published past the deadline before the close job ran). A draft
     * never opened for ordering, so it stays editable whatever its deadline.
     */
    public function menusAreLocked(): bool
    {
        return $this->status !== 'draft' && $this->isLocked();
    }

    /** Planned cost: the sum of every menu's cost, each at its own prices. */
    public function estimatedCost(): float
    {
        return round($this->menus->sum(fn (LunchMenu $menu) => $menu->estimatedCost()), 2);
    }
}
