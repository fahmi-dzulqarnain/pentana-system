<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Models\Passkey;
use Spatie\LaravelPasskeys\Support\Config as PasskeyConfig;
use Spatie\LaravelPasskeys\Support\Serializer;
use Throwable;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * JSON passkey ceremonies for the mobile app. Reuses spatie/laravel-passkeys
 * (same models, RP config and ceremony validators) but is stateless: the
 * challenge from the options call is cached under a random "state" token and
 * echoed back on verify, instead of the package's session storage.
 */
class PasskeyController extends Controller
{
    private const TTL_MINUTES = 5;

    /** Discoverable (usernameless) sign-in: returns the request options + a state token. */
    public function loginOptions(): JsonResponse
    {
        // Built inline (not via the spatie action) because that action writes to the
        // session, which isn't started on stateless API routes.
        $options = new PublicKeyCredentialRequestOptions(
            challenge: Str::random(),
            rpId: PasskeyConfig::getRelyingPartyId(),
            allowCredentials: [],
        );

        return $this->optionsResponse(Serializer::make()->toJson($options));
    }

    public function loginVerify(Request $request): JsonResponse
    {
        $data = $request->validate(['state' => 'required|string', 'credential' => 'required']);

        $optionsJson = Cache::pull($this->cacheKey($data['state']));
        if (! $optionsJson) {
            return response()->json(['message' => 'Passkey session expired. Please try again.'], 422);
        }

        try {
            $passkey = app(FindPasskeyToAuthenticateAction::class)
                ->execute($this->credentialJson($data['credential']), $optionsJson);
        } catch (Throwable) {
            $passkey = null; // malformed credential -> treat as a failed attempt
        }

        $user = $passkey?->authenticatable;
        if (! $user || ! $user->isActivated()) {
            return response()->json(['message' => 'The provided credentials are incorrect.'], 422);
        }

        return response()->json([
            'token' => $user->createToken('passkey')->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    /** Register a passkey for the signed-in member: returns the creation options + state. */
    public function registerOptions(Request $request): JsonResponse
    {
        $optionsJson = app(GeneratePasskeyRegisterOptionsAction::class)->execute($request->user());

        return $this->optionsResponse($optionsJson);
    }

    public function registerVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => 'required|string',
            'credential' => 'required',
            'name' => 'nullable|string|max:255',
        ]);

        $optionsJson = Cache::pull($this->cacheKey($data['state']));
        if (! $optionsJson) {
            return response()->json(['message' => 'Passkey session expired. Please try again.'], 422);
        }

        try {
            $passkey = app(StorePasskeyAction::class)->execute(
                $request->user(),
                $this->credentialJson($data['credential']),
                $optionsJson,
                $request->getHost(),
                ['name' => $data['name'] ?? 'iPhone'],
            );
        } catch (Throwable) {
            return response()->json(['message' => 'Could not register that passkey. Please try again.'], 422);
        }

        return response()->json($this->summary($passkey), 201);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->passkeys()->latest()->get()->map(fn (Passkey $p) => $this->summary($p)),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()->passkeys()->whereKey($id)->delete();

        return response()->json(['message' => 'Passkey removed.']);
    }

    private function optionsResponse(string $optionsJson): JsonResponse
    {
        $state = Str::random(40);
        Cache::put($this->cacheKey($state), $optionsJson, now()->addMinutes(self::TTL_MINUTES));

        return response()->json(['state' => $state, 'publicKey' => json_decode($optionsJson, true)]);
    }

    /** The client may send the WebAuthn credential as an object or a JSON string. */
    private function credentialJson(mixed $credential): string
    {
        return is_string($credential) ? $credential : json_encode($credential);
    }

    private function cacheKey(string $state): string
    {
        return 'passkey_options:'.$state;
    }

    private function summary(Passkey $passkey): array
    {
        return [
            'id' => $passkey->id,
            'name' => $passkey->name,
            'last_used_at' => $passkey->last_used_at?->toIso8601String(),
            'created_at' => $passkey->created_at?->toIso8601String(),
        ];
    }
}
