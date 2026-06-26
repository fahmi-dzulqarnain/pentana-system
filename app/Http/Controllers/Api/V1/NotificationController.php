<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        ]);

        return response()->json([
            'data' => $items,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /** Mark every unread notification as read (called when the member opens the list). */
    public function markRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['unread_count' => 0]);
    }
}
