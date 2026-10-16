<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Payment\PaymentProof;
use App\Support\Locales;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Concerns\InteractsWithPasskeys;

class User extends Authenticatable implements FilamentUser, HasLocalePreference, HasPasskeys
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, InteractsWithPasskeys, Notifiable;

    /**
     * Members and admins share the panel; per-resource gating handles the rest. Disabled
     * members are refused, which also ends a session that was open when they were disabled.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return ! $this->isDisabled();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'member_category_id',
        'custom_fee',
        'birthday',
        'birthday_email_sent_at',
        'activated_at',
    ];

    /**
     * Language for notifications and email. Laravel renders each notification in
     * the recipient's preference; disabled or unset falls back to English.
     */
    public function preferredLocale(): string
    {
        // Not config('app.locale'): App::setLocale() rewrites it, so it is the sender's language.
        return Locales::isEnabled($this->locale) ? $this->locale : 'en';
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'custom_fee' => 'decimal:2',
            'birthday' => 'date',
            'birthday_email_sent_at' => 'datetime',
            'activated_at' => 'datetime',
            'invited_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * Return an empty string rather than null when no password has been set.
     * This prevents password_verify() from receiving null (a deprecation in
     * PHP 8.2+ and a TypeError in PHP 9), ensuring unclaimed users are cleanly
     * rejected by Auth::attempt without any warning.
     */
    public function getAuthPassword(): string
    {
        return $this->password ?? '';
    }

    public function isActivated(): bool
    {
        return $this->activated_at !== null;
    }

    /** How long an invitation stays open before the unclaimed account is disabled. */
    public const INVITATION_VALID_DAYS = 30;

    public function invitationExpiresAt(): ?Carbon
    {
        return $this->invited_at?->copy()->addDays(self::INVITATION_VALID_DAYS);
    }

    /**
     * A disabled member can't sign in or claim, isn't billed and gets no notifications.
     * Two ways to get here: an admin disabled them (disabled_at, e.g. they resigned), or
     * they were invited and never claimed within the window.
     */
    public function isDisabled(): bool
    {
        return $this->isManuallyDisabled() || $this->invitationExpired();
    }

    public function isManuallyDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Invited, never claimed, and the invitation has run out. Derived rather than stored, so
     * no job has to flip it, and resending the invitation (a fresh invited_at) re-enables
     * the account. Members who were never invited never expire.
     */
    public function invitationExpired(): bool
    {
        return $this->activated_at === null
            && $this->invited_at !== null
            && $this->invitationExpiresAt()->isPast();
    }

    /** Same rule as isDisabled(), as a query. */
    public function scopeDisabled(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNotNull('disabled_at')
            ->orWhere(fn (Builder $q) => $q->whereNull('activated_at')
                ->where('invited_at', '<=', now()->subDays(self::INVITATION_VALID_DAYS))));
    }

    /** Everyone isDisabled() is false for: who gets bills, notifications and appears in pickers. */
    public function scopeEnabled(Builder $query): void
    {
        $query->whereNull('disabled_at')
            ->where(fn (Builder $q) => $q->whereNotNull('activated_at')
                ->orWhereNull('invited_at')
                ->orWhere('invited_at', '>', now()->subDays(self::INVITATION_VALID_DAYS)));
    }

    public function memberCategory()
    {
        return $this->belongsTo(MemberCategory::class);
    }

    public function paymentProofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /** APNs device tokens this member has registered (used by the apn notification channel). */
    public function routeNotificationForApn(): array
    {
        return $this->deviceTokens()->pluck('token')->all();
    }

    /**
     * The member's available credit: the sum of every verified payment proof's
     * unallocated remainder (amount_verified minus what it has paid towards bills).
     */
    public function getCreditAttribute(): float
    {
        $credit = $this->paymentProofs()
            ->where('status', 'verified')
            ->withSum('allocations', 'amount_allocated')
            ->get()
            ->sum(fn (PaymentProof $proof) => (float) $proof->amount_verified
                - (float) $proof->allocations_sum_amount_allocated);

        return round($credit, 2);
    }

    /**
     * Priority Logic:
     * 1. Individual custom_fee override.
     * 2. Category default_fee.
     * 3. Default to 0.
     */
    public function getActiveFeeAttribute(): float
    {
        if ($this->custom_fee !== null) {
            return (float) $this->custom_fee;
        }

        return (float) ($this->memberCategory?->default_fee ?? 0);
    }
}
