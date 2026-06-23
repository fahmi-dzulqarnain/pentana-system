<?php

namespace App\Models;

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
        'location',
        'status',
        'capacity',
        'registration_closes_at',
        'ends_at',
        'reminder_sent_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'registration_closes_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'capacity' => 'integer',
    ];

    public function registrations(): HasMany
    {
        return $this->hasMany(ActivityRegistration::class);
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

    /** Registration closes at an explicit deadline if set, otherwise when the activity starts. */
    public function registrationClosesAt(): Carbon
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
        return now()->gte($this->starts_at);
    }

    /** Members may register only while published and before registration closes. */
    public function isOpenForRegistration(): bool
    {
        return $this->status === 'published' && now()->lt($this->registrationClosesAt());
    }
}
