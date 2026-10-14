<?php

namespace Tests\Feature\Localisation;

use App\Models\User;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserLocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_exposes_an_enabled_locale_as_its_preference(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $user = User::factory()->create(['locale' => 'id']);

        $this->assertInstanceOf(HasLocalePreference::class, $user);
        $this->assertSame('id', $user->fresh()->preferredLocale());
    }

    public function test_no_or_disabled_locale_means_no_preference(): void
    {
        config(['app.locales' => 'en,id']);

        $this->assertNull(User::factory()->create(['locale' => null])->preferredLocale());
        $this->assertNull(User::factory()->create(['locale' => 'ms'])->preferredLocale());
    }
}
