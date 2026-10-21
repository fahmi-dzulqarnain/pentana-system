<?php

namespace App\Notifications;

use App\Filament\Pages\MyLunch;
use App\Mail\BrandedMail;
use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\QueuedEmail;
use App\Notifications\Concerns\PushesViaApn;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Apn\ApnMessage;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * An admin set (or cleared) the member's lunch pick on their behalf.
 * A null option means "not attending". Same channels and shape as LunchPickRemoved.
 */
class LunchPickSetByAdmin extends Notification
{
    use PushesViaApn;

    public function __construct(public Lunch $lunch, public ?LunchOption $option) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->withApn($notifiable, ['database', 'mail']);
    }

    public function toApn(object $notifiable): ApnMessage
    {
        return ApnMessage::create()->title($this->title())->body($this->body());
    }

    private function title(): string
    {
        return __('Your lunch pick for :date was changed', ['date' => $this->lunch->date->translatedFormat('D, j M')]);
    }

    private function body(): string
    {
        if (! $this->option) {
            return __('An admin marked you as not attending.');
        }

        return __('An admin set it to :pick.', ['pick' => $this->option->lunchMenu->catererMeal->name.' · '.$this->option->mealOption->name]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new BrandedMail)
            ->tone('lunch', '🍽️', __('Lunch'))
            ->subject($this->title())
            ->greeting($this->title())
            ->line($this->body())
            ->action(__('Place your order'), MyLunch::getUrl(panel: 'account'))
            ->withSymfonyMessage(function (SymfonyEmail $message) {
                $message->getHeaders()->addTextHeader(QueuedEmail::PRIORITY_HEADER, '10');
            });
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon('heroicon-o-arrow-path')
            ->warning()
            ->actions([
                Action::make('order')->label(__('Order now'))->url(MyLunch::getUrl(panel: 'account'))->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
