<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Support\Nickname;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(Request $request): UserResource
    {
        $user = $request->user();

        // Normalise first so ' Fahmi ' and '' behave the same as on the web form. Non-strings are
        // left alone for the 'string' rule to reject.
        if ($request->has('nickname') && (is_string($request->input('nickname')) || $request->input('nickname') === null)) {
            $request->merge(['nickname' => Nickname::normalise($request->input('nickname'))]);
        }

        // `present` stops a client that omits the key from silently clearing the nickname.
        $validated = $request->validate([
            'nickname' => ['present', ...Nickname::rules($user)],
        ]);

        $user->forceFill(['nickname' => $validated['nickname']])->save();

        return new UserResource($user->fresh());
    }
}
