<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SupportTicketStatus;
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
        [$nextLunch, $otherLunch] = $this->lunches($user);

        return response()->json([
            'data' => [
                'bills' => $this->bills($user),
                'next_lunch' => $nextLunch ? $this->lunchPayload($nextLunch, $user) : null,
                'other_lunch' => $otherLunch ? $this->lunchPayload($otherLunch, $user) : null,
                'next_activity' => $this->nextActivity($user),
                'open_activities_count' => $this->openActivitiesCount($user),
                'pending_proofs_count' => $user->paymentProofs()->where('status', 'pending')->count(),
                // Tickets where the committee answered and the member hasn't yet: the Home card count.
                'support_replies_waiting' => $user->supportTickets()->where('status', SupportTicketStatus::WaitingOnMember)->count(),
                'rejected_proof' => $this->rejectedProof($user),
                'support_reply_ticket' => $this->supportReplyTicket($user),
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
            'pending_proofs_amount' => number_format((float) $user->paymentProofs()->where('status', 'pending')->sum('amount_claimed'), 2, '.', ''),
        ];
    }

    /**
     * The lunch card target and the next different upcoming lunch, from one query.
     * Prefer the earliest upcoming lunch the member still needs to vote on (open +
     * not responded); otherwise fall back to the next upcoming lunch (so the card
     * still shows the menu). Window matches the Lunch tab.
     *
     * @return array{0: ?Lunch, 1: ?Lunch}
     */
    private function lunches(User $user): array
    {
        $lunches = Lunch::query()
            ->with(['menus.caterer', 'menus.catererMeal', 'responses' => fn ($query) => $query->where('user_id', $user->id)])
            ->visibleToMembers()
            ->whereDate('date', '>=', today())
            ->whereDate('date', '<=', today()->addDays(14))
            ->orderBy('date')
            ->get();

        $target = $lunches->first(
            fn (Lunch $lunch) => $lunch->isOpenForVoting() && $lunch->responses->first() === null
        ) ?? $lunches->first();

        return [$target, $target ? $lunches->first(fn (Lunch $lunch) => $lunch->id !== $target->id) : null];
    }

    private function lunchPayload(Lunch $lunch, User $user): array
    {
        return [
            'id' => $lunch->id,
            'date' => $lunch->date->toDateString(),
            'caterer' => $lunch->catererLabel(),
            'menu' => $lunch->legacyMenuText($user->preferredLocale()),
            'menus' => $lunch->menus->map(fn ($menu) => [
                'caterer' => $menu->caterer?->name,
                'menu' => $menu->catererMeal?->name,
            ])->values(),
            'is_open' => $lunch->isOpenForVoting(),
            'responded' => $lunch->responses->first() !== null,
            'vote_deadline' => $lunch->deadline?->toIso8601String(),
        ];
    }

    /** The member's latest proof, only while it is a recent rejection nothing has superseded. */
    private function rejectedProof(User $user): ?array
    {
        $proof = $user->paymentProofs()->orderByDesc('created_at')->orderByDesc('id')->first();

        if (! $proof || $proof->status !== 'rejected' || ! $proof->rejected_at || $proof->rejected_at->lt(now()->subDays(14))) {
            return null;
        }

        return [
            'id' => $proof->id,
            'amount_claimed' => number_format((float) $proof->amount_claimed, 2, '.', ''),
            'admin_note' => $proof->admin_note,
            'rejected_at' => $proof->rejected_at->toIso8601String(),
        ];
    }

    /** The ticket that has been waiting on the member longest. */
    private function supportReplyTicket(User $user): ?array
    {
        $ticket = $user->supportTickets()
            ->where('status', SupportTicketStatus::WaitingOnMember)
            ->orderBy('last_message_at')
            ->orderBy('id')
            ->first();

        return $ticket ? ['id' => $ticket->id, 'subject' => $ticket->subject] : null;
    }

    /** The member's next registered/waitlisted activity. */
    private function nextActivity(User $user): ?array
    {
        $mine = fn ($query) => $query->where('user_id', $user->id)->where('status', '!=', 'cancelled');

        $activity = Activity::query()
            ->where('status', 'published')
            ->upcoming()
            ->whereHas('registrations', $mine)
            ->with(['registrations' => $mine])
            ->first();

        if (! $activity) {
            return null;
        }

        return [
            'id' => $activity->id,
            'title' => $activity->title,
            'starts_at' => $activity->starts_at?->toIso8601String(),
            'timeframe' => $activity->timeframe,
            'my_status' => $activity->registrations->first()?->status ?? 'none',
        ];
    }

    /** Upcoming activities still open for registration that the member hasn't joined. */
    private function openActivitiesCount(User $user): int
    {
        return Activity::query()
            ->where('status', 'published')
            ->upcoming()
            ->whereDoesntHave('registrations', fn ($query) => $query
                ->where('user_id', $user->id)->where('status', '!=', 'cancelled'))
            ->get()
            ->filter(fn (Activity $activity) => $activity->isOpenForRegistration())
            ->count();
    }
}
