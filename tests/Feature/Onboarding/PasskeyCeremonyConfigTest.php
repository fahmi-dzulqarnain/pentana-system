<?php

namespace Tests\Feature\Onboarding;

use App\Passkeys\LocalAwareCeremonyStepManagerFactoryAction;
use Tests\TestCase;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

class PasskeyCeremonyConfigTest extends TestCase
{
    public function test_the_local_aware_ceremony_factory_is_wired(): void
    {
        $this->assertSame(
            LocalAwareCeremonyStepManagerFactoryAction::class,
            config('passkeys.actions.configure_ceremony_step_manager_factory'),
        );
    }

    public function test_it_builds_a_factory_and_secures_localhost_in_local_env_without_error(): void
    {
        // Force local env so the securedRelyingPartyId branch runs - this guards that
        // webauthn-lib still exposes setSecuredRelyingPartyId (deprecated, removed in 6.0).
        $this->app['env'] = 'local';

        $factory = app(LocalAwareCeremonyStepManagerFactoryAction::class)->execute();

        $this->assertInstanceOf(CeremonyStepManagerFactory::class, $factory);
    }
}
