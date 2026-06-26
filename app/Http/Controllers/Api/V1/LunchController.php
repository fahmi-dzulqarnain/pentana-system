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
                'catererMeal',
                'caterer',
                'options.mealOption',
                'responses' => fn ($query) => $query->where('user_id', $request->user()->id),
            ])
            ->where('status', 'published')
            ->whereDate('date', '>=', today())
            ->whereDate('date', '<=', today()->addDays(14))
            ->orderBy('date')
            ->get();

        return LunchResource::collection($lunches);
    }

    public function respond(Request $request, Lunch $lunch): JsonResponse
    {
        $data = $request->validate(['meal_option_id' => 'nullable|integer']);

        try {
            // Acts on the authenticated member; all voting rules live in the service.
            app(LunchVotingService::class)->respond($lunch, $request->user(), $data['meal_option_id'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $lunch->load([
            'catererMeal',
            'caterer',
            'options.mealOption',
            'responses' => fn ($query) => $query->where('user_id', $request->user()->id),
        ]);

        return (new LunchResource($lunch))->response();
    }
}
