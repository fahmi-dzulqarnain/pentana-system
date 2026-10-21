<?php

namespace App\Livewire\Admin;

use App\Models\Lunch;
use App\Models\User;
use App\Services\LunchReminderService;
use App\Services\LunchVoteBreakdown;
use App\Services\LunchVotingService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Who voted what, on the admin lunch edit page. Public props are client-controlled, so every
 * action re-checks the admin and re-resolves the lunch, member and option server-side.
 */
class LunchVotes extends Component
{
    #[Locked]
    public int $lunchId;

    /**
     * Filament's Livewire schema component always passes the form's `record` too. Taking it here
     * keeps Livewire from echoing the whole model into the page as an HTML attribute; it's unused.
     */
    public function mount(int $lunchId, mixed $record = null): void
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $this->lunchId = $lunchId;
    }

    public function setPick(int $memberId, ?int $lunchOptionId): void
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $lunch = Lunch::findOrFail($this->lunchId);
        $member = User::findOrFail($memberId);

        try {
            app(LunchVotingService::class)->setPickAsAdmin($lunch, $member, $lunchOptionId);
        } catch (RuntimeException $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }

        Notification::make()->title(__('Pick saved.'))->success()->send();
    }

    public function remind(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $lunch = Lunch::findOrFail($this->lunchId);

        try {
            $count = app(LunchReminderService::class)->remindNonResponders($lunch, auth()->user());
        } catch (RuntimeException $e) {
            Notification::make()->title(__($e->getMessage()))->danger()->send();

            return;
        }

        if ($count === 0) {
            Notification::make()->title(__('Everyone has already responded.'))->warning()->send();

            return;
        }

        // Delivery is deferred until after the response, so this confirms the send, not the delivery.
        Notification::make()->title(__('Reminder sent to :count members.', ['count' => $count]))->success()->send();
    }

    public function render(): View
    {
        $lunch = Lunch::with(['manualReminderSender', 'menus.catererMeal', 'menus.options.mealOption'])->findOrFail($this->lunchId);

        // The click-a-name menu offers enabled options only; a disabled one shows in the breakdown just because someone picked it.
        $choices = $lunch->menus->flatMap(fn ($menu) => $menu->options->where('enabled', true)->map(fn ($option) => [
            'id' => $option->id,
            'label' => trim(($menu->catererMeal?->name ?? '').' · '.($option->mealOption?->name ?? ''), ' ·'),
        ]))->values()->all();

        return view('livewire.admin.lunch-votes', [
            'lunch' => $lunch,
            'breakdown' => LunchVoteBreakdown::for($lunch),
            'choices' => $choices,
            'editable' => $lunch->status === 'published',
            'canRemind' => $lunch->isOpenForVoting(),
        ]);
    }
}
