<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A ticket screenshot for the member's app, by bearer token. Same rules as the web route,
 * except admins get 403: this endpoint serves members' apps only.
 */
class SupportAttachmentController extends Controller
{
    public function __invoke(Request $request, SupportMessage $message): StreamedResponse
    {
        $user = $request->user();
        abort_if($user->is_admin, 403);

        $allowed = ! $user->isDisabled() && $message->ticket->user_id === $user->id;
        abort_unless($allowed && filled($message->attachment_path), 404);
        abort_unless(Storage::disk('local')->exists($message->attachment_path), 404);

        return Storage::disk('local')->response($message->attachment_path, headers: ['X-Content-Type-Options' => 'nosniff']);
    }
}
