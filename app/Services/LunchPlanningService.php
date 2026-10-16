<?php

namespace App\Services;

use App\Models\LunchMenu;

class LunchPlanningService
{
    /** Ensure the menu has one option row per its catalog menu's options (idempotent). */
    public function syncOptionsFromMenu(LunchMenu $menu): void
    {
        $menu->loadMissing('catererMeal.options');

        foreach ($menu->catererMeal->options as $catererMealOption) {
            $menu->options()->firstOrCreate(
                ['meal_option_id' => $catererMealOption->meal_option_id],
                ['lunch_id' => $menu->lunch_id, 'enabled' => true, 'default_count' => 0],
            );
        }
    }
}
