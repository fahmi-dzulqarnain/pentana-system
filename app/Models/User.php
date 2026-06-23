<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Payment\PaymentProof;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Concerns\InteractsWithPasskeys;

class User extends Authenticatable implements FilamentUser, HasPasskeys
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithPasskeys, Notifiable;

    /**
     * Members and admins share the panel; per-resource gating handles the rest.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
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

    public function memberCategory()
    {
        return $this->belongsTo(MemberCategory::class);
    }

    public function paymentProofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class);
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
