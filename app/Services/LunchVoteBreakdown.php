<?php

namespace App\Services;

use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\User;
use Illuminate\Support\Collection;

/** Who voted what on a lunch day, for the admin breakdown and its print page. */
class LunchVoteBreakdown
{
    /**
     * @return array{
     *   menus: array<int, array{meal: ?string, caterer: ?string, options: array<int, array{lunch_option_id: int, name: ?string, enabled: bool, members: array<int, array{id: int, name: string, disabled: bool}>}>}>,
     *   not_attending: array<int, array{id: int, name: string, disabled: bool}>,
     *   not_responded: array<int, array{id: int, name: string, disabled: bool}>,
     *   counts: array{ordering: int, not_attending: int, not_responded: int}
     * }
     */
    public static function for(Lunch $lunch): array
    {
        $lunch->loadMissing(['menus.caterer', 'menus.catererMeal', 'menus.options.mealOption', 'responses.user']);

        $enabledIds = User::enabled()->whereIn('id', $lunch->responses->pluck('user_id'))->pluck('id')->all();

        $member = fn ($response) => [
            'id' => (int) $response->user_id,
            'name' => (string) $response->user?->name,
            'disabled' => ! in_array($response->user_id, $enabledIds),
        ];

        $byOption = $lunch->responses->whereNotNull('lunch_option_id')->groupBy('lunch_option_id');

        $ordering = 0;
        $menus = $lunch->menus->map(function ($menu) use ($byOption, $member, &$ordering) {
            $options = $menu->options
                ->filter(fn (LunchOption $o) => $o->enabled || $byOption->has($o->id))
                ->map(function (LunchOption $o) use ($byOption, $member, &$ordering) {
                    $members = self::sorted(($byOption->get($o->id) ?? collect())->map($member));
                    $ordering += count($members);

                    return [
                        'lunch_option_id' => $o->id,
                        'name' => $o->mealOption?->name,
                        'enabled' => $o->enabled,
                        'members' => $members,
                    ];
                })
                ->values()
                ->all();

            return [
                'meal' => $menu->catererMeal?->name,
                'caterer' => $menu->caterer?->name,
                'options' => $options,
            ];
        })->all();

        // lunch_option_id is the single predicate: a response without one is not attending, so no member is lost.
        $notAttending = self::sorted($lunch->responses->whereNull('lunch_option_id')->map($member));

        $notResponded = self::sorted(
            $lunch->nonResponders()->get(['id', 'name'])->map(fn (User $u) => ['id' => $u->id, 'name' => (string) $u->name, 'disabled' => false])
        );

        return [
            'menus' => $menus,
            'not_attending' => $notAttending,
            'not_responded' => $notResponded,
            'counts' => [
                'ordering' => $ordering,
                'not_attending' => count($notAttending),
                'not_responded' => count($notResponded),
            ],
        ];
    }

    /** @return array<int, array{id: int, name: string, disabled: bool}> */
    private static function sorted(Collection $members): array
    {
        return $members->sortBy(fn ($m) => mb_strtolower($m['name']))->values()->all();
    }
}
