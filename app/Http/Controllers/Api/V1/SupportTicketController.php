<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SupportTicketType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreSupportReplyRequest;
use App\Http\Requests\Api\StoreSupportTicketRequest;
use App\Http\Resources\Api\SupportMessageResource;
use App\Http\Resources\Api\SupportTicketDetailResource;
use App\Http\Resources\Api\SupportTicketResource;
use App\Models\SupportTicket;
use App\Services\SupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Throwable;

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

    public function store(StoreSupportTicketRequest $request, SupportService $support): JsonResponse
    {
        $path = $request->file('screenshot')?->store('support', 'local');

        try {
            $ticket = $support->open(
                $request->user(),
                SupportTicketType::from($request->validated('type')),
                $request->validated('subject'),
                $request->validated('body'),
                $path ?: null,
            );
        } catch (Throwable $e) {
            // Nothing references the file if the ticket wasn't created; don't leave it behind.
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return $this->detail($ticket)->response()->setStatusCode(201);
    }

    public function reply(StoreSupportReplyRequest $request, SupportTicket $supportTicket, SupportService $support): JsonResponse
    {
        $message = $support->reply($this->ownedTicket($request, $supportTicket), $request->user(), $request->validated('body'));

        return (new SupportMessageResource($message))->response()->setStatusCode(201);
    }

    public function resolve(Request $request, SupportTicket $supportTicket, SupportService $support): SupportTicketDetailResource
    {
        $ticket = $this->ownedTicket($request, $supportTicket);
        $support->resolveByMember($ticket, $request->user());

        return $this->detail($ticket);
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
