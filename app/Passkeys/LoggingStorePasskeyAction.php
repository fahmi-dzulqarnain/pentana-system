<?php

namespace App\Passkeys;

use Illuminate\Support\Facades\Log;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Passkey;
use Throwable;

/**
 * Spatie's PasskeysComponent::storePasskey() catches any Throwable from the store
 * action and replaces it with a generic "something went wrong" message, discarding
 * the real cause (usually a WebAuthn attestation-verification failure wrapped in
 * InvalidPasskey). This subclass logs the underlying error before re-throwing, so
 * registration failures are diagnosable.
 */
class LoggingStorePasskeyAction extends StorePasskeyAction
{
    public function execute(
        HasPasskeys $authenticatable,
        string $passkeyJson,
        string $passkeyOptionsJson,
        string $hostName,
        array $additionalProperties = [],
    ): Passkey {
        try {
            return parent::execute($authenticatable, $passkeyJson, $passkeyOptionsJson, $hostName, $additionalProperties);
        } catch (Throwable $e) {
            Log::error('Passkey registration failed', [
                'host' => $hostName,
                'message' => $e->getMessage(),
                'cause' => $e->getPrevious()?->getMessage(),
                'cause_class' => $e->getPrevious() ? $e->getPrevious()::class : null,
            ]);

            throw $e;
        }
    }
}
