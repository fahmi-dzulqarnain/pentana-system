<?php

namespace App\Services;

use App\Models\Lunch;

class LunchPlanningService
{
    /** Ensure the lunch has one option row per its menu's options (idempotent). */
    public function syncOptionsFromMenu(Lunch $lunch): void
    {
        $lunch->loadMissing('catererMeal.options');

        foreach ($lunch->catererMeal->options as $catererMealOption) {
            $lunch->options()->firstOrCreate(
                ['meal_option_id' => $catererMealOption->meal_option_id],
                ['enabled' => true, 'default_count' => 0],
            );
        }
    }
}
