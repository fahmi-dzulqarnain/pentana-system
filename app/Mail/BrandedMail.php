<?php

namespace App\Mail;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * A MailMessage that renders in PENTANA's branded layout instead of Laravel's default
 * Markdown template. Build it exactly like a MailMessage (greeting, line, action...);
 * tone() picks the header colour and emoji for the kind of email.
 */
class BrandedMail extends MailMessage
{
    /**
     * Header colours are dark enough for white text (WCAG AA, 4.5:1) at both ends of the
     * gradient; bg is the solid fallback for clients that ignore gradients (Outlook).
     */
    public const TONES = [
        'default' => ['bg' => '#1c1917', 'gradient' => 'radial-gradient(circle at 100% 0%,rgba(245,158,11,0.45) 0%,rgba(28,25,23,0) 55%)', 'cta' => '#1c1917', 'emoji' => '📬'],
        'lunch' => ['bg' => '#166534', 'gradient' => 'linear-gradient(135deg,#15803d 0%,#065f46 100%)', 'cta' => '#15803d', 'emoji' => '🍱'],
        'activity' => ['bg' => '#4338ca', 'gradient' => 'linear-gradient(135deg,#4f46e5 0%,#6d28d9 100%)', 'cta' => '#4f46e5', 'emoji' => '🗓️'],
        'payment' => ['bg' => '#047857', 'gradient' => 'linear-gradient(135deg,#047857 0%,#115e59 100%)', 'cta' => '#047857', 'emoji' => '✅'],
        'bill' => ['bg' => '#92400e', 'gradient' => 'linear-gradient(135deg,#92400e 0%,#9a3412 100%)', 'cta' => '#b45309', 'emoji' => '🧾'],
        'alert' => ['bg' => '#b91c1c', 'gradient' => 'linear-gradient(135deg,#b91c1c 0%,#9f1239 100%)', 'cta' => '#b91c1c', 'emoji' => '⚠️'],
    ];

    public function __construct()
    {
        $this->view(['html' => 'mail.notification', 'text' => 'mail.notification-text']);
        $this->tone('default');
    }

    /** @param  key-of<self::TONES>  $tone */
    public function tone(string $tone, ?string $emoji = null, ?string $kicker = null): static
    {
        $this->viewData['tone'] = self::TONES[$tone];
        $this->viewData['emoji'] = $emoji ?? self::TONES[$tone]['emoji'];
        $this->viewData['kicker'] = $kicker;

        return $this;
    }
}
