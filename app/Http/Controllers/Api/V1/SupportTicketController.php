<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\SupportTicketDetailResource;
use App\Http\Resources\Api\SupportTicketResource;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The member's own support tickets for the apps. Admins answer on the web, so they get 403
 * here; another member's ticket is 404 so its existence isn't leaked. Every change goes
 * through SupportService, the same rules and notifications as the web.
 */
class SupportTicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_if($request->user()->is_admin, 403);

        return SupportTicketResource::collection(
            $request->user()->supportTickets()->with('latestMessage')->latest('last_message_at')->latest('id')->get()
        );
    }

    public function show(Request $request, SupportTicket $supportTicket): SupportTicketDetailResource
    {
        return $this->detail($this->ownedTicket($request, $supportTicket));
    }

    private function ownedTicket(Request $request, SupportTicket $ticket): SupportTicket
    {
        abort_if($request->user()->is_admin, 403);
        abort_unless($ticket->user_id === $request->user()->id, 404);

        return $ticket;
    }

    private function detail(SupportTicket $ticket): SupportTicketDetailResource
    {
        return new SupportTicketDetailResource($ticket->fresh(['messages', 'latestMessage', 'closedBy']));
    }
}
