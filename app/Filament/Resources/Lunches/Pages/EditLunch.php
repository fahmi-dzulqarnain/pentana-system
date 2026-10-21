<?php

namespace App\Filament\Resources\Lunches\Pages;

use App\Filament\Resources\Lunches\LunchResource;
use App\Notifications\LunchPickRemoved;
use App\Services\LunchMenuChanges;
use App\Services\LunchPublisher;
use App\Services\MemberNotifier;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EditLunch extends EditRecord
{
    protected static string $resource = LunchResource::class;

    /** Picks the pending save will drop, captured before the repeater deletes removed menus. */
    private ?Collection $droppedResponses = null;

    /** @var array{0: string, 1: int}|null per-request memo of the confirmation count: [state hash, count] */
    private ?array $pendingDroppedMemo = null;

    /** Set only while the confirmed Save action runs; private, so a Livewire request can't set it. */
    private bool $dropsConfirmed = false;

    /**
     * Every save path (button, Enter / implicit form submit, a direct Livewire `save` call) lands
     * here. If the save would drop members' picks and the admin hasn't confirmed, open the Save
     * action's confirmation instead of saving.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        // Before asking to confirm: a save that can't succeed shouldn't ask about dropped picks.
        $this->ensureNoMealCollision();

        if (! $this->dropsConfirmed && $this->pendingDroppedCount() > 0) {
            $this->mountAction('save', context: ['schemaComponent' => 'content.form-actions']);

            return;
        }

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    /**
     * The repeater writes rows one by one, so swapping two rows' meals (A,B to B,A) would hit the
     * (lunch_id, caterer_meal_id) unique index mid-save and 500. Say so on the field instead.
     */
    private function ensureNoMealCollision(): void
    {
        if ($this->record->menusAreLocked()) {
            return;
        }

        if (($key = app(LunchMenuChanges::class)->firstMealCollision($this->record, $this->menusState())) !== null) {
            throw ValidationException::withMessages([
                "data.menus.{$key}.caterer_meal_id" => __('This menu is still on another row while saving. To swap two menus, drag the rows into the new order instead.'),
            ]);
        }
    }

    /** Status changes only through Publish and the close command, never from form state. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['status']);

        return $data;
    }

    protected function beforeSave(): void
    {
        $this->droppedResponses = null;

        // Locked days don't save the menus repeaters at all (see LunchForm), so there is nothing to carry.
        // Checked server-side: the form's disabled state alone can be tampered with.
        if ($this->record->menusAreLocked()) {
            return;
        }

        $this->droppedResponses = app(LunchMenuChanges::class)->droppedResponses($this->record, $this->menusState());
    }

    protected function afterSave(): void
    {
        // The lock was decided in beforeSave, against the record as it was when the repeaters
        // saved; this save may itself move the deadline into the past, which must not strand
        // the menu changes that were just written.
        if ($this->droppedResponses !== null) {
            $removedFor = app(LunchMenuChanges::class)->apply($this->record, $this->droppedResponses);

            // A draft's picks aren't real orders yet; only a published day tells members.
            if ($this->record->status === 'published' && $removedFor->isNotEmpty()) {
                app(MemberNotifier::class)->sendAfterResponse($removedFor, new LunchPickRemoved($this->record->fresh()));
            }
        }

        $this->droppedResponses = null;

        // The nested options repeaters may now be stale; reload the form from the database.
        // (Without this, saving again re-inserted the old menu's option rows: prod bug 2026-10-16.)
        $this->record->unsetRelation('menus');
        $this->record->unsetRelation('options');
        $this->fillForm();
    }

    protected function getSaveFormAction(): Action
    {
        // A plain action (not a form submit) so it can open a confirmation modal first.
        return parent::getSaveFormAction()
            ->submit(null)
            ->action(function (): void {
                // Reaching here means the modal was confirmed, or no picks would drop.
                $this->dropsConfirmed = true;

                try {
                    $this->save();
                } finally {
                    $this->dropsConfirmed = false;
                }
            })
            // A modal description alone would always open the modal; only ask when picks would go.
            ->modal(fn (): bool => $this->pendingDroppedCount() > 0)
            ->requiresConfirmation(fn (): bool => $this->pendingDroppedCount() > 0)
            ->modalDescription(fn (): string => __(':count members\' picks will be removed and they will be asked to choose again.', [
                'count' => $this->pendingDroppedCount(),
            ]));
    }

    private function pendingDroppedCount(): int
    {
        if ($this->record->menusAreLocked()) {
            return 0;
        }

        $hash = md5(serialize($this->menusState()));
        if ($this->pendingDroppedMemo === null || $this->pendingDroppedMemo[0] !== $hash) {
            $count = app(LunchMenuChanges::class)->droppedResponses($this->record, $this->menusState())->count();
            $this->pendingDroppedMemo = [$hash, $count];
        }

        return $this->pendingDroppedMemo[1];
    }

    /** @return array<array-key, mixed> */
    private function menusState(): array
    {
        $state = $this->data['menus'] ?? [];

        return is_array($state) ? $state : [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('Publish'))
                ->visible(fn () => $this->record->status === 'draft')
                ->requiresConfirmation()
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->action(function () {
                    if (app(LunchPublisher::class)->publish(collect([$this->record]))->isEmpty()) {
                        return;
                    }

                    // Keep the form state in sync so a later Save doesn't write the stale 'draft' back.
                    $this->refreshFormData(['status']);

                    FilamentNotification::make()
                        ->title(__('Published: members notified'))
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
