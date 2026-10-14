<?php

namespace App\Passkeys;

use Spatie\LaravelPasskeys\Actions\ConfigureCeremonyStepManagerFactoryAction;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * WebAuthn requires a secure (HTTPS) context. Browsers treat http://localhost as
 * secure, so the client-side ceremony works in dev - but webauthn-lib's server-side
 * verification still rejects the http scheme with "Invalid scheme. HTTPS required."
 *
 * In local dev we mark localhost / 127.0.0.1 as "secured relying party ids" so the
 * verifier accepts http for them. Production (served over HTTPS) keeps the strict
 * default - this exemption only applies when APP_ENV=local.
 *
 * This factory is shared by both the registration and authentication ceremonies, so
 * the exemption fixes passkey create AND passkey login on localhost.
 */
class LocalAwareCeremonyStepManagerFactoryAction extends ConfigureCeremonyStepManagerFactoryAction
{
    public function execute(): CeremonyStepManagerFactory
    {
        $factory = parent::execute();

        if (app()->isLocal()) {
            // Deprecated in webauthn-lib 5.2 (use setAllowedOrigins in 6.0), but the
            // purpose-built way to allow http on a localhost RP id in 5.x.
            $factory->setSecuredRelyingPartyId(['localhost', '127.0.0.1']);
        }

        return $factory;
    }
}
