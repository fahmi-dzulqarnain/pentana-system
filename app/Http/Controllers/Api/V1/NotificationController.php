<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Notifications\SupportReplyFromCommittee;
use App\Notifications\SupportTicketClosed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /** The member's database notifications (newest first) + the unread count for the badge. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = $user->notifications()->latest()->limit(50)->get()->map(fn ($notification) => [
            'id' => $notification->id,
            'title' => $notification->data['title'] ?? '',
            'body' => $notification->data['body'] ?? null,
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
            'support_ticket_id' => $this->supportTicketId($notification),
        ]);

        return response()->json([
            'data' => $items,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * The ticket a member-facing support notice is about, so the app can open its conversation.
     * Read from the stored "View ticket" link, which also covers notices sent before this field
     * existed. Admin-facing notices get null: admins answer on the web, the app would 403.
     */
    private function supportTicketId(DatabaseNotification $notification): ?int
    {
        if (! in_array($notification->type, [SupportReplyFromCommittee::class, SupportTicketClosed::class], true)) {
            return null;
        }

        $url = $notification->data['actions'][0]['url'] ?? '';

        return preg_match('#/support-tickets/(\d+)#', $url, $m) ? (int) $m[1] : null;
    }

    /** Mark every unread notification as read (called when the member opens the list). */
    public function markRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['unread_count' => 0]);
    }
}
