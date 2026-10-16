<?php

namespace App\Http\Resources\Api;

use App\Models\Lunch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lunch */
class LunchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // `responses` is eager-loaded filtered to the caller, so first() is *their* vote (or none).
        $mine = $this->responses->first();

        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'caterer' => $this->catererLabel(),
            'menu' => $this->legacyMenuText($request->user()?->preferredLocale()),
            'deadline' => $this->deadline?->toIso8601String(),
            'is_open' => $this->isOpenForVoting(),
            // Apps from before multi-menu days only understand one menu: on a several-menu day they get
            // no options (so can't order the wrong thing) and the update prompt in `menu` instead.
            'options' => $this->hasSeveralMenus()
                ? []
                : $this->menus->first()?->options
                    ->where('enabled', true)
                    ->map(fn ($option) => [
                        'meal_option_id' => $option->meal_option_id,
                        'name' => $option->mealOption?->name,
                    ])
                    ->values() ?? [],
            'menus' => $this->menus->map(fn ($menu) => [
                'id' => $menu->id,
                'caterer' => $menu->caterer?->name,
                'menu' => $menu->catererMeal?->name,
                'options' => $menu->options
                    ->where('enabled', true)
                    ->map(fn ($option) => [
                        'lunch_option_id' => $option->id,
                        'meal_option_id' => $option->meal_option_id,
                        'name' => $option->mealOption?->name,
                    ])
                    ->values(),
            ])->values(),
            'responded' => $mine !== null,
            'my_meal_option_id' => $mine?->meal_option_id,
            'my_lunch_option_id' => $mine?->lunch_option_id,
        ];
    }
}
