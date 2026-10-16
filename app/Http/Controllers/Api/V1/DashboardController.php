<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lunch;
use App\Models\Payment\FeePeriod;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** A compact, single round-trip summary across bills, lunch, activities and proofs. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'bills' => $this->bills($user),
                'next_lunch' => $this->nextLunch($user),
                'next_activity' => $this->nextActivity($user),
                'open_activities_count' => $this->openActivitiesCount($user),
                'pending_proofs_count' => $user->paymentProofs()->where('status', 'pending')->count(),
            ],
        ]);
    }

    /** Mirrors BillController::summary so the dashboard and Bills tab agree. */
    private function bills(User $user): array
    {
        $bills = FeePeriod::forMember($user->id)->get();

        $totalOutstanding = FinanceService::outstanding($bills);

        return [
            'total_outstanding' => number_format(round($totalOutstanding, 2), 2, '.', ''),
            'available_credit' => number_format((float) $user->credit, 2, '.', ''),
            'unpaid_count' => $bills->where('status', '!=', 'paid')->count(),
        ];
    }

    /**
     * Prefer the earliest upcoming lunch the member still needs to vote on (open +
     * not responded); otherwise fall back to the next upcoming lunch (so the card
     * still shows the menu). Window matches the Lunch tab.
     */
    private function nextLunch(User $user): ?array
    {
        $lunches = Lunch::query()
            ->with(['menus.caterer', 'menus.catererMeal', 'responses' => fn ($query) => $query->where('user_id', $user->id)])
            ->where('status', 'published')
            ->whereDate('date', '>=', today())
            ->whereDate('date', '<=', today()->addDays(14))
            ->orderBy('date')
            ->get();

        if ($lunches->isEmpty()) {
            return null;
        }

        $target = $lunches->first(
            fn (Lunch $lunch) => $lunch->isOpenForVoting() && $lunch->responses->first() === null
        ) ?? $lunches->first();

        return [
            'id' => $target->id,
            'date' => $target->date->toDateString(),
            'caterer' => $target->catererLabel(),
            'menu' => $target->legacyMenuText($user->preferredLocale()),
            'menus' => $target->menus->map(fn ($menu) => [
                'caterer' => $menu->caterer?->name,
                'menu' => $menu->catererMeal?->name,
            ])->values(),
            'is_open' => $target->isOpenForVoting(),
            'responded' => $target->responses->first() !== null,
        ];
    }

    /** The member's next registered/waitlisted activity. */
    private function nextActivity(User $user): ?array
    {
        $mine = fn ($query) => $query->where('user_id', $user->id)->where('status', '!=', 'cancelled');

        $activity = Activity::query()
            ->where('status', 'published')
            ->where('starts_at', '>=', now())
            ->whereHas('registrations', $mine)
            ->with(['registrations' => $mine])
            ->orderBy('starts_at')
            ->first();

        if (! $activity) {
            return null;
        }

        return [
            'id' => $activity->id,
            'title' => $activity->title,
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'my_status' => $activity->registrations->first()?->status ?? 'none',
        ];
    }

    /** Upcoming activities still open for registration that the member hasn't joined. */
    private function openActivitiesCount(User $user): int
    {
        return Activity::query()
            ->where('status', 'published')
            ->where('starts_at', '>=', now())
            ->whereDoesntHave('registrations', fn ($query) => $query
                ->where('user_id', $user->id)->where('status', '!=', 'cancelled'))
            ->get()
            ->filter(fn (Activity $activity) => $activity->isOpenForRegistration())
            ->count();
    }
}
