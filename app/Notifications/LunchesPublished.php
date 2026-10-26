<?php

namespace App\Notifications;

use App\Filament\Pages\MyLunch;
use App\Mail\BrandedMail;
use App\Models\Lunch;
use App\Models\QueuedEmail;
use App\Notifications\Concerns\PushesViaApn;
use App\Support\TimeFormat;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use NotificationChannels\Apn\ApnMessage;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * Several lunch days published together (typically a week): one notification listing every
 * day, instead of one LunchPublished per day. Same channels and shape; sent inline (no queue worker).
 */
class LunchesPublished extends Notification
{
    use PushesViaApn;

    /** @var Collection<int, Lunch> in date order */
    public Collection $lunches;

    /** @param  Collection<int, Lunch>  $lunches */
    public function __construct(Collection $lunches)
    {
        $this->lunches = $lunches->sortBy(fn (Lunch $lunch) => $lunch->date)->values();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->withApnOrMail($notifiable, ['database']);
    }

    public function toApn(object $notifiable): ApnMessage
    {
        return ApnMessage::create()->title($this->title())->body($this->body());
    }

    private function title(): string
    {
        return __('Lunch published for :count days', ['count' => $this->lunches->count()]);
    }

    private function body(): string
    {
        return __(':first to :last. Order each day before its deadline.', [
            'first' => $this->lunches->first()->date->translatedFormat('D j M'),
            'last' => $this->lunches->last()->date->translatedFormat('D j M'),
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new BrandedMail)
            ->tone('lunch', '🍱', __('Lunch'))
            ->subject($this->title())
            ->greeting(__('Lunch is on the menu'))
            ->line(__('Menus are published for these days:'));

        foreach ($this->lunches as $lunch) {
            $mail->line(__(':date: :menu (order by :deadline)', [
                'date' => $lunch->date->translatedFormat('D, j M'),
                'menu' => $this->menusLine($lunch) ?: __('Menu'),
                'deadline' => $lunch->deadline->translatedFormat(TimeFormat::time().', D j M'),
            ]));
        }

        return $mail
            ->action(__('Place your order'), MyLunch::getUrl(panel: 'account'))
            ->withSymfonyMessage(function (SymfonyEmail $message) {
                $message->getHeaders()->addTextHeader(QueuedEmail::PRIORITY_HEADER, '10');
                $message->getHeaders()->addTextHeader(QueuedEmail::EXPIRES_HEADER, $this->lunches->max('deadline')->toIso8601String());
            });
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon('heroicon-o-ticket')
            ->success()
            ->actions([
                Action::make('order')->label(__('Order now'))->url(MyLunch::getUrl(panel: 'account'))->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    /** Every menu of the day, in display order, joined with " · "; empty when the day has none. */
    private function menusLine(Lunch $lunch): string
    {
        $lunch->loadMissing('menus.catererMeal');

        return $lunch->menus
            ->map(fn ($menu) => $menu->catererMeal?->name)
            ->filter()
            ->implode(' · ');
    }
}
