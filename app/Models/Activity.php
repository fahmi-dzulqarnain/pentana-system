<?php

namespace App\Models;

use App\Support\TimeFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'starts_at',
        'timeframe',
        'location',
        'status',
        'capacity',
        'registration_closes_at',
        'ends_at',
        'reminder_sent_at',
        'registration_fields',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'registration_closes_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'capacity' => 'integer',
        'registration_fields' => 'array',
    ];

    public function registrations(): HasMany
    {
        return $this->hasMany(ActivityRegistration::class);
    }

    /** True when the admin has attached at least one registration question. */
    public function hasRegistrationFields(): bool
    {
        return ! empty($this->registration_fields);
    }

    public function registeredCount(): int
    {
        return $this->registrations->where('status', 'registered')->count();
    }

    public function waitlistCount(): int
    {
        return $this->registrations->where('status', 'waitlisted')->count();
    }

    public function attendedCount(): int
    {
        return $this->registrations->where('attended', true)->count();
    }

    /** Not yet scheduled: only a rough timeframe is known, so it has no start time. */
    public function isComingSoon(): bool
    {
        return $this->starts_at === null;
    }

    /** "Sunday, 14 Dec · 9:00 AM", or "Coming soon · December 2026" before the date is set. */
    public function whenLabel(): string
    {
        if ($this->starts_at) {
            return $this->starts_at->translatedFormat('l, j M · '.TimeFormat::time());
        }

        return $this->timeframe
            ? __('Coming soon · :timeframe', ['timeframe' => $this->timeframe])
            : __('Coming soon');
    }

    /** Not yet started, including coming-soon activities - dated ones first, soonest first. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '>=', now()))
            // Portable NULLS LAST (Postgres and SQLite disagree on the default).
            ->orderByRaw('starts_at is null')
            ->orderBy('starts_at');
    }

    /**
     * Registration closes at an explicit deadline if set, otherwise when the activity starts.
     * Null for a coming-soon activity without a deadline: it stays open until scheduled.
     */
    public function registrationClosesAt(): ?Carbon
    {
        return $this->registration_closes_at ?? $this->starts_at;
    }

    /** Free seats remaining, or null when capacity is unlimited. */
    public function spotsLeft(): ?int
    {
        return $this->capacity === null
            ? null
            : max(0, $this->capacity - $this->registeredCount());
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->registeredCount() >= $this->capacity;
    }

    public function isPast(): bool
    {
        return $this->starts_at !== null && now()->gte($this->starts_at);
    }

    /** Members may register only while published and before registration closes. */
    public function isOpenForRegistration(): bool
    {
        $closesAt = $this->registrationClosesAt();

        return $this->status === 'published' && ($closesAt === null || now()->lt($closesAt));
    }
}
