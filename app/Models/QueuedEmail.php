<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * An email that did not fit the budget, stored as raw MIME.
 *
 * MIME is stored rather than re-rendered so a reminder queued today says what it said
 * today - draining must not re-run Blade against a bill that has since been paid.
 */
class QueuedEmail extends Model
{
    public const MAX_ATTEMPTS = 3;

    public const DEFAULT_PRIORITY = 50;

    public const PRIORITY_HEADER = 'X-Pentana-Priority';

    protected $fillable = [
        'mime', 'recipients', 'sender', 'subject',
        'priority', 'attempts', 'last_error', 'failed_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'priority' => 'integer',
            'attempts' => 'integer',
            'failed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public static function spool(RawMessage $message, ?Envelope $envelope, ?string $error = null): self
    {
        $recipients = $envelope
            ? array_map(fn (Address $a) => $a->getAddress(), $envelope->getRecipients())
            : [];

        $sender = $envelope?->getSender()?->getAddress() ?? (string) config('mail.from.address');

        $priority = self::DEFAULT_PRIORITY;
        $subject = null;

        if ($message instanceof Email) {
            $subject = $message->getSubject();
            $header = $message->getHeaders()->get(self::PRIORITY_HEADER);

            if ($header !== null) {
                $priority = (int) $header->getBodyAsString();
            }
        }

        return static::create([
            'mime' => $message->toString(),
            'recipients' => $recipients,
            'sender' => $sender,
            'subject' => $subject,
            'priority' => $priority,
            'attempts' => $error === null ? 0 : 1,
            'last_error' => $error,
        ]);
    }

    /**
     * @param  array<int, int>  $skip  ids already attempted in this drain run - a message
     *                                 that just failed must not be retried immediately, or
     *                                 a brief provider outage burns all three attempts in
     *                                 one minute.
     */
    public static function nextPending(array $skip = []): ?self
    {
        return static::whereNull('sent_at')
            ->whereNull('failed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->when($skip !== [], fn ($query) => $query->whereNotIn('id', $skip))
            ->orderBy('priority')
            ->orderBy('created_at')
            ->first();
    }

    public function rawMessage(): RawMessage
    {
        return new RawMessage($this->mime);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            new Address($this->sender),
            array_map(fn (string $a) => new Address($a), $this->recipients),
        );
    }

    public function markSent(): void
    {
        $this->forceFill(['sent_at' => now()])->save();
    }

    public function markFailed(string $error): void
    {
        $attempts = $this->attempts + 1;

        $this->forceFill([
            'attempts' => $attempts,
            'last_error' => mb_substr($error, 0, 1000),
            'failed_at' => $attempts >= self::MAX_ATTEMPTS ? now() : null,
        ])->save();
    }
}
