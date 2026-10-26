<?php

namespace App\Models;

use App\Enums\SupportTicketStatus;
use App\Enums\SupportTicketType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A member's question, bug report or suggestion to the committee. State changes go through SupportService. */
class SupportTicket extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'type', 'subject', 'status', 'closed_at', 'closed_by_id', 'last_message_at'];

    protected function casts(): array
    {
        return [
            'type' => SupportTicketType::class,
            'status' => SupportTicketStatus::class,
            'closed_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->oldest()->oldest('id');
    }

    public function scopeNeedsReply(Builder $query): void
    {
        $query->where('status', SupportTicketStatus::NeedsReply);
    }
}
