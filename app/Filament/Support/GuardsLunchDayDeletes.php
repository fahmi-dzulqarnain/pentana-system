<?php

namespace App\Filament\Support;

use App\Models\Contracts\UsedByLunchDays;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Lunch days keep their caterers, menus and options: lunch_menus restricts those deletes, and
 * an option delete would cascade members' picks away. These guards refuse in-use records
 * with a notice instead of a database error.
 */
final class GuardsLunchDayDeletes
{
    /**
     * Refuse to delete a record that lunch days use.
     *
     * @param  Closure(int): string  $message  the refusal, given the number of lunch days
     */
    public static function single(DeleteAction $action, Closure $message): DeleteAction
    {
        return $action->before(function (DeleteAction $action, UsedByLunchDays $record) use ($message): void {
            $count = $record->lunchDaysCount();

            if ($count > 0) {
                Notification::make()->danger()->title($message($count))->send();
                $action->cancel();
            }
        });
    }

    /** Delete the selected records lunch days don't use, and report how many were skipped. */
    public static function bulk(DeleteBulkAction $action): DeleteBulkAction
    {
        return $action->using(function (DeleteBulkAction $action, Collection $records): void {
            foreach ($records as $record) {
                if ($record instanceof UsedByLunchDays && $record->lunchDaysCount() > 0) {
                    $action->reportBulkProcessingFailure(
                        'used_by_lunch_days',
                        fn (int $count): string => __('Skipped :count still used by lunch days.', ['count' => $count]),
                    );

                    continue;
                }

                $record->delete() || $action->reportBulkProcessingFailure();
            }
        });
    }
}
