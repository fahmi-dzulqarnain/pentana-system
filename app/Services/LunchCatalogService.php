<?php

namespace App\Services;

use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\Meal;
use Illuminate\Support\Facades\DB;

class LunchCatalogService
{
    /**
     * Copy a catalog meal into a caterer's menu, carrying its name, base price,
     * image, and available options (each with a null/base price override).
     */
    public function copyMealToCaterer(Meal $meal, Caterer $caterer): CatererMeal
    {
        return DB::transaction(function () use ($meal, $caterer) {
            $catererMeal = $caterer->meals()->create([
                'meal_id' => $meal->id,
                'name' => $meal->name,
                'price' => $meal->base_price,
                'image' => $meal->image,
            ]);

            foreach ($meal->options as $option) {
                $catererMeal->options()->create([
                    'meal_option_id' => $option->id,
                    'price' => null,
                ]);
            }

            return $catererMeal;
        });
    }
}
