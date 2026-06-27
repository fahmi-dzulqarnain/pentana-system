<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /** Register (or re-point) an APNs device token for the signed-in member. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string|max:255',
            'platform' => 'nullable|string|max:20',
        ]);

        // Upsert by token: the same device reassigns to whoever is currently signed in.
        DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'] ?? 'ios'],
        );

        return response()->json(['message' => 'Device registered.'], 201);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        $request->user()->deviceTokens()->where('token', $token)->delete();

        return response()->json(['message' => 'Device removed.']);
    }
}
