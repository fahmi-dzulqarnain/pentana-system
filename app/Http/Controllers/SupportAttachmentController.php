<?php

namespace App\Http\Controllers;

use App\Models\SupportMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a support-ticket screenshot from the private disk to the ticket's member or an
 * admin. Anyone else gets 404, not 403, so the URL never confirms a ticket exists.
 */
class SupportAttachmentController extends Controller
{
    public function __invoke(Request $request, SupportMessage $message): StreamedResponse
    {
        $user = $request->user();
        $allowed = ! $user->isDisabled() && ($user->is_admin || $message->ticket->user_id === $user->id);

        abort_unless($allowed && filled($message->attachment_path), 404);
        abort_unless(Storage::disk('local')->exists($message->attachment_path), 404);

        return Storage::disk('local')->response($message->attachment_path);
    }
}
