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
 * Last-call nudge for a member who hasn't voted on lunches closing within a day.
 * A week published together usually shares one deadline, so the member gets ONE nudge
 * listing every day they haven't answered - not one per day (five emails each, which
 * blew the daily email budget for four days).
 * Delivered INLINE (not ShouldQueue): this deployment has no queue worker.
 */
class LunchVoteNudge extends Notification
{
    use PushesViaApn;

    /** @var Collection<int, Lunch> in date order */
    public Collection $lunches;

    /** @param  Lunch|Collection<int, Lunch>  $lunches */
    public function __construct(Lunch|Collection $lunches)
    {
        $this->lunches = Collection::wrap($lunches)->sortBy(fn (Lunch $lunch) => $lunch->date)->values();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->withApnOrMail($notifiable, ['database']);
    }

    public function toApn(object $notifiable): ApnMessage
    {
        return ApnMessage::create()->title($this->pushTitle())->body($this->pushBody());
    }

    private function pushTitle(): string
    {
        if ($this->lunches->count() > 1) {
            return __('Last call: lunch for :days', ['days' => $this->daysLine()]);
        }

        return __('Last call: :menu', ['menu' => $this->menusLine($this->lunches->first()) ?: __('lunch menu')]);
    }

    private function pushBody(): string
    {
        return __("Voting closes at :deadline and you haven't responded.", ['deadline' => $this->deadline()]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new BrandedMail)->tone('lunch', '⏳', __('Lunch'));

        if ($this->lunches->count() > 1) {
            $mail->subject(__('Last call to order lunch for :days', ['days' => $this->daysLine()]))
                ->greeting(__('Voting closes soon'))
                ->line(__("You haven't responded to lunch for these days:"));

            foreach ($this->lunches as $lunch) {
                $mail->line(__(':date: :menu (order by :deadline)', [
                    'date' => $lunch->date->translatedFormat('D, j M'),
                    'menu' => $this->menusLine($lunch) ?: __('Menu'),
                    'deadline' => $lunch->deadline->translatedFormat(TimeFormat::time().', D j M'),
                ]));
            }
        } else {
            $lunch = $this->lunches->first();
            $mail->subject(__('Last call to order lunch for :date', ['date' => $lunch->date->translatedFormat('D, j M')]))
                ->greeting(__('Voting closes soon'))
                ->line(__("You haven't responded to the lunch for :date.", ['date' => $lunch->date->translatedFormat('l, j M')]))
                ->line(__('Voting closes at :deadline.', ['deadline' => $this->deadline()]));
        }

        return $mail
            ->action(__('Place your order'), $this->url())
            ->withSymfonyMessage(function (SymfonyEmail $message) {
                $message->getHeaders()->addTextHeader(QueuedEmail::PRIORITY_HEADER, '10');
                // Still worth reading while any listed day is open.
                $message->getHeaders()->addTextHeader(QueuedEmail::EXPIRES_HEADER, $this->lunches->max('deadline')->toIso8601String());
            });
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->pushTitle())
            ->body($this->pushBody())
            ->icon('heroicon-o-clock')
            ->warning()
            ->actions([
                Action::make('view')->label(__('Vote now'))->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    /** The earliest deadline: the one the member has to beat first. */
    private function deadline(): string
    {
        return $this->lunches->min('deadline')->translatedFormat(TimeFormat::time().', D j M');
    }

    /** "Mon 26, Tue 27, Wed 28" */
    private function daysLine(): string
    {
        return $this->lunches->map(fn (Lunch $lunch) => $lunch->date->translatedFormat('D j'))->implode(', ');
    }

    private function url(): string
    {
        return MyLunch::getUrl(panel: 'account');
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
