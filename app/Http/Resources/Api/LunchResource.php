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
            'caterer' => $this->caterer?->name,
            'menu' => $this->catererMeal?->name,
            'deadline' => $this->deadline?->toIso8601String(),
            'is_open' => $this->isOpenForVoting(),
            'options' => $this->options
                ->where('enabled', true)
                ->map(fn ($option) => [
                    'meal_option_id' => $option->meal_option_id,
                    'name' => $option->mealOption?->name,
                ])
                ->values(),
            'responded' => $mine !== null,
            'my_meal_option_id' => $mine?->meal_option_id,
        ];
    }
}
