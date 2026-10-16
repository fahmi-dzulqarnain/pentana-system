<?php

namespace App\Services;

use App\Models\CatererMealOption;
use App\Models\Lunch;
use App\Models\LunchMenu;
use App\Models\LunchOption;
use App\Models\LunchResponse;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Decides what an edit of a day's menus does to members' picks.
 *
 * A pick survives a menu swap when the new menu has the same meal option; it is dropped
 * when its menu is removed or swapped to one without that option. Adding a menu leaves
 * picks alone. The dry run (`droppedResponses`) must happen before the form saves: the
 * relationship repeater deletes removed menus, and their options and picks cascade away.
 */
class LunchMenuChanges
{
    public function __construct(private LunchPlanningService $planning) {}

    /**
     * The picks the submitted repeater state would drop. Existing items are keyed
     * `record-<lunch_menu id>`; removed items are absent; new items have other keys.
     *
     * @param  array<array-key, mixed>  $menusState
     * @return Collection<int, LunchResponse>
     */
    public function droppedResponses(Lunch $lunch, array $menusState): Collection
    {
        $menus = $lunch->menus()->get()->keyBy('id');

        /** @var array<int, int|null> $submittedMeal lunch_menu id => submitted caterer_meal_id */
        $submittedMeal = [];
        foreach ($menusState as $key => $item) {
            if (! is_string($key) || ! str_starts_with($key, 'record-')) {
                continue;
            }

            $meal = is_array($item) ? ($item['caterer_meal_id'] ?? null) : null;
            $submittedMeal[(int) substr($key, strlen('record-'))] = filled($meal) ? (int) $meal : null;
        }

        $responses = $lunch->responses()
            ->whereNotNull('lunch_option_id')
            ->with('lunchOption')
            ->get();

        $newMealIds = collect($submittedMeal)
            ->filter(fn (?int $meal, int $menuId) => $meal !== null && $menus->has($menuId) && $meal !== $menus[$menuId]->caterer_meal_id)
            ->unique()
            ->values();

        /** @var Collection<int, Collection<int, int>> $optionsByMeal caterer_meal_id => meal_option ids */
        $optionsByMeal = CatererMealOption::whereIn('caterer_meal_id', $newMealIds)
            ->get(['caterer_meal_id', 'meal_option_id'])
            ->groupBy('caterer_meal_id')
            ->map(fn (Collection $rows) => $rows->pluck('meal_option_id')->map(fn ($id) => (int) $id));

        return $responses->filter(function (LunchResponse $response) use ($menus, $submittedMeal, $optionsByMeal): bool {
            $menuId = $response->lunchOption?->lunch_menu_id;

            if ($menuId === null || ! $menus->has($menuId)) {
                return false;
            }

            if (! array_key_exists($menuId, $submittedMeal)) {
                return true; // the menu is being removed
            }

            $meal = $submittedMeal[$menuId];
            if ($meal === null || $meal === $menus[$menuId]->caterer_meal_id) {
                return false; // unchanged (an empty select fails validation anyway)
            }

            return ! ($optionsByMeal->get($meal)?->contains($response->meal_option_id) ?? false);
        })->values();
    }

    /**
     * The state key of the first item whose save would hit the (lunch_id, caterer_meal_id)
     * unique index, or null. The repeater deletes removed rows first, then writes items one
     * by one in form order, so swapping two rows' meals (A,B to B,A) collides mid-save even
     * though the submitted state has no duplicate. Simulates that write order.
     *
     * @param  array<array-key, mixed>  $menusState
     */
    public function firstMealCollision(Lunch $lunch, array $menusState): ?string
    {
        $menus = $lunch->menus()->get()->keyBy('id');

        /** @var array<int, int|string> $holder caterer_meal_id => lunch_menu id (or state key) holding it */
        $holder = [];
        foreach ($menus as $menu) {
            if (array_key_exists("record-{$menu->id}", $menusState)) {
                $holder[$menu->caterer_meal_id] = $menu->id;
            }
        }

        foreach ($menusState as $key => $item) {
            $meal = is_array($item) && filled($item['caterer_meal_id'] ?? null) ? (int) $item['caterer_meal_id'] : null;
            if ($meal === null) {
                continue;
            }

            $menuId = is_string($key) && str_starts_with($key, 'record-') ? (int) substr($key, strlen('record-')) : null;
            $menu = $menuId !== null ? $menus->get($menuId) : null;

            if ($menu !== null && $meal === $menu->caterer_meal_id) {
                continue;
            }

            if (array_key_exists($meal, $holder) && $holder[$meal] !== $menu?->id) {
                return (string) $key;
            }

            if ($menu !== null) {
                unset($holder[$menu->caterer_meal_id]);
            }
            $holder[$meal] = $menu?->id ?? (string) $key;
        }

        return null;
    }

    /**
     * After the save: delete the dropped picks, rebuild each menu's options, point the
     * carried picks at their (possibly new) option and remove options no longer on a
     * swapped menu.
     *
     * @param  Collection<int, LunchResponse>  $dropped
     * @return Collection<int, User> the members whose pick was removed
     */
    public function apply(Lunch $lunch, Collection $dropped): Collection
    {
        $users = User::whereKey($dropped->pluck('user_id')->unique()->all())->get();

        // Picks on removed menus are already gone by cascade; this catches the swapped ones.
        LunchResponse::whereKey($dropped->modelKeys())->delete();

        foreach ($lunch->menus()->with('catererMeal.options')->get() as $menu) {
            $this->planning->syncOptionsFromMenu($menu);
            $this->repointCarriedPicks($menu);
            $this->removeOptionsNoLongerOnTheMenu($menu);
        }

        return $users;
    }

    private function repointCarriedPicks(LunchMenu $menu): void
    {
        $optionIds = $menu->options()->pluck('id', 'meal_option_id');

        LunchResponse::whereIn('lunch_option_id', $menu->options()->select('id'))
            ->whereNotNull('meal_option_id')
            ->get()
            ->each(function (LunchResponse $response) use ($optionIds): void {
                $target = $optionIds->get($response->meal_option_id);

                if ($target !== null && $target !== $response->lunch_option_id) {
                    $response->update(['lunch_option_id' => $target]);
                }
            });
    }

    /**
     * Options whose meal option isn't on the menu's catalog menu. One still holding a pick
     * is kept: that can only happen when the catalog itself changed, and a pick should
     * never disappear silently. Picks on a swapped-away option were dropped above.
     */
    private function removeOptionsNoLongerOnTheMenu(LunchMenu $menu): void
    {
        $onMenu = $menu->catererMeal->options->pluck('meal_option_id')->all();

        LunchOption::where('lunch_menu_id', $menu->id)
            ->whereNotIn('meal_option_id', $onMenu)
            ->whereDoesntHave('responses')
            ->delete();
    }
}
