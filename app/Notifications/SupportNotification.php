<?php

namespace App\Notifications;

use App\Filament\Resources\SupportTickets\SupportTicketResource;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Notifications\Concerns\PushesViaApn;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\Apn\ApnMessage;

/**
 * Shared shape of every support-ticket notice: bell + push, never email (the daily email
 * budget is smaller than the member list). Member-facing pushes carry the custom key
 * `ticket_id` so the iOS app can open the ticket thread; admin-facing ones do not, because
 * the member support API refuses admins (the bell's deep link follows the same rule).
 * The bell links to the ticket.
 * Subject and message are the sender's own words: escaped, never translated.
 */
abstract class SupportNotification extends Notification
{
    use PushesViaApn;

    public function __construct(public SupportTicket $ticket, public ?SupportMessage $message = null) {}

    abstract protected function title(): string;

    abstract protected function body(): string;

    protected function icon(): string
    {
        return 'heroicon-o-chat-bubble-left-right';
    }

    /** Whether the push should deep-link into the member's in-app ticket thread. */
    protected function deepLinksToThread(): bool
    {
        return false;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->withApn($notifiable, ['database']);
    }

    public function toApn(object $notifiable): ApnMessage
    {
        $apn = ApnMessage::create()
            ->title($this->title())
            ->body($this->message
                ? $this->ticket->subject.': '.Str::limit($this->message->body, 120)
                : $this->ticket->subject);

        return $this->deepLinksToThread() ? $apn->custom('ticket_id', $this->ticket->id) : $apn;
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body(e($this->body()))
            ->icon($this->icon())
            ->actions([
                Action::make('view')->label(__('View ticket'))->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function memberName(): string
    {
        return (string) $this->ticket->user?->getFilamentName();
    }

    private function url(): string
    {
        return SupportTicketResource::getUrl('view', ['record' => $this->ticket], panel: 'account');
    }
}
