<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lunch extends Model
{
    use HasFactory;

    protected $table = 'lunches';

    protected $fillable = ['date', 'deadline', 'status', 'published_at', 'vote_nudge_sent_at'];

    protected $casts = [
        'date' => 'date',
        'deadline' => 'datetime',
        'vote_nudge_sent_at' => 'datetime',
        'published_at' => 'datetime',
        'announced_at' => 'datetime',
        'manual_reminder_sent_at' => 'datetime',
        'manual_reminder_sent_by' => 'integer',
    ];

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

    /** The admin who last pressed "Remind now"; null when nobody has, or that admin was deleted. */
    public function manualReminderSender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_reminder_sent_by');
    }

    /** Enabled members who have no response row for this lunch: who the vote nudge and the admin reminder target. */
    public function nonResponders(): Builder
    {
        return User::enabled()->whereNotIn('id', $this->responses()->select('user_id'));
    }

    /**
     * Lunches members see: published, and closed ones too. app:close-lunch-orders flips a lunch to
     * 'closed' once its deadline passes, and members still need it to see what they ordered.
     * Drafts stay hidden.
     */
    public function scopeVisibleToMembers(Builder $query): Builder
    {
        return $query->whereIn('status', ['published', 'closed']);
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

    /**
     * Picks per lunch option, so the same meal option on two menus stays apart.
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

    /** What a pre-menus app shows as the caterer: the one caterer, or every distinct caterer joined with " · ". */
    public function catererLabel(): ?string
    {
        return $this->menus->map(fn (LunchMenu $menu) => $menu->caterer?->name)->filter()->unique()->implode(' · ') ?: null;
    }

    /** What a pre-menus app shows as the menu: the one menu's name, or a prompt to update when there are several. */
    public function legacyMenuText(?string $locale = null): ?string
    {
        return $this->hasSeveralMenus()
            ? __('Several menus today. Update the app to order.', [], $locale)
            : $this->menus->first()?->catererMeal?->name;
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
