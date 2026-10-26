<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a support ticket. `from_committee` is fixed when written, so a reply stays
 * signed "Committee" even if its author later stops being an admin.
 */
class SupportMessage extends Model
{
    use HasFactory;

    protected $fillable = ['support_ticket_id', 'user_id', 'body', 'attachment_path', 'from_committee'];

    protected function casts(): array
    {
        return ['from_committee' => 'boolean'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
