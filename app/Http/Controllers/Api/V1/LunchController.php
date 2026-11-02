<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\LunchResource;
use App\Models\Lunch;
use App\Services\LunchVotingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class LunchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $lunches = Lunch::query()
            ->with([
                'menus.caterer',
                'menus.catererMeal',
                'menus.options.mealOption',
                'responses' => fn ($query) => $query->where('user_id', $request->user()->id),
            ])
            ->visibleToMembers()
            // From Monday, so earlier days this week (closed, read-only) still show what was ordered.
            ->whereDate('date', '>=', today()->startOfWeek())
            ->whereDate('date', '<=', today()->addDays(14))
            ->orderBy('date')
            ->get();

        return LunchResource::collection($lunches);
    }

    public function respond(Request $request, Lunch $lunch): JsonResponse
    {
        $data = $request->validate([
            'lunch_option_id' => 'nullable|integer',
            'meal_option_id' => 'nullable|integer',
        ]);

        try {
            // Acts on the authenticated member; all voting rules live in the service.
            $service = app(LunchVotingService::class);
            $lunchOptionId = $data['lunch_option_id'] ?? null;
            // Apps from before multi-menu days send meal_option_id; lunch_option_id wins when both come.
            if ($lunchOptionId === null && ($data['meal_option_id'] ?? null) !== null) {
                $lunchOptionId = $service->resolveLegacyMealOption($lunch, (int) $data['meal_option_id']);
            }
            $service->respond($lunch, $request->user(), $lunchOptionId === null ? null : (int) $lunchOptionId);
        } catch (RuntimeException $e) {
            return response()->json(['message' => __($e->getMessage(), [], $request->user()->preferredLocale())], 422);
        }

        $lunch->load([
            'menus.caterer',
            'menus.catererMeal',
            'menus.options.mealOption',
            'responses' => fn ($query) => $query->where('user_id', $request->user()->id),
        ]);

        return (new LunchResource($lunch))->response();
    }
}
