<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        // Reject unknown, unclaimed (no password / not activated), or wrong-password accounts
        // with the same generic 401 - no user enumeration. getAuthPassword() returns '' for a
        // null password, so Hash::check cleanly fails for imported-but-unclaimed members.
        if (! $user || ! $user->isActivated() || $user->isDisabled()
            || ! Hash::check($request->validated('password'), $user->getAuthPassword())) {
            return response()->json(['message' => 'The provided credentials are incorrect.'], 401);
        }

        return response()->json([
            'token' => $user->createToken($request->validated('device_name'))->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
