<?php

namespace Tests\Feature\Onboarding;

use App\Livewire\ClaimAccount;
use App\Models\User;
use App\Services\AccountClaimService;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** Opens the claim wizard the way a member does: through the signed link in their invitation. */
trait OpensClaimLinks
{
    protected function openClaimLink(User $user): Testable
    {
        parse_str((string) parse_url(app(AccountClaimService::class)->link($user), PHP_URL_QUERY), $query);

        return Livewire::withQueryParams(['invited' => $query['invited']])
            ->test(ClaimAccount::class, ['user' => $user->id]);
    }
}
