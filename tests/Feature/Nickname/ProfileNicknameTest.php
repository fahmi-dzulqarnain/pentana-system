<?php

namespace Tests\Feature\Nickname;

use App\Filament\Auth\Profile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileNicknameTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = User::factory()->create(['locale' => 'en', 'activated_at' => now()]);
        $this->actingAs($this->member);
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    public function test_member_can_set_nickname(): void
    {
        Livewire::test(Profile::class)
            ->set('data.nickname', '  Fahmi ')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Fahmi', $this->member->fresh()->nickname);
    }

    public function test_member_can_clear_nickname(): void
    {
        $this->member->forceFill(['nickname' => 'Fahmi'])->save();

        Livewire::test(Profile::class)
            ->set('data.nickname', '')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->member->fresh()->nickname);
    }

    public function test_duplicate_nickname_is_rejected(): void
    {
        User::factory()->create(['nickname' => 'fahmi']);

        Livewire::test(Profile::class)
            ->set('data.nickname', 'Fahmi')
            ->call('save')
            ->assertHasFormErrors(['nickname']);

        $this->assertNull($this->member->fresh()->nickname);
    }

    public function test_padded_thirty_character_nickname_saves(): void
    {
        $nickname = str_repeat('a', 30);

        Livewire::test(Profile::class)
            ->set('data.nickname', "  {$nickname}  ")
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($nickname, $this->member->fresh()->nickname);
    }

    public function test_losing_a_race_for_a_nickname_is_a_form_error(): void
    {
        $fired = false;
        User::updating(function () use (&$fired) {
            if (! $fired) {
                $fired = true;
                User::factory()->create(['nickname' => 'Fahmi']);
            }
        });

        Livewire::test(Profile::class)
            ->set('data.nickname', 'Fahmi')
            ->call('save')
            ->assertHasFormErrors(['nickname']);

        $this->assertNull($this->member->fresh()->nickname);
    }

    public function test_full_name_field_is_still_shown(): void
    {
        Livewire::test(Profile::class)
            ->assertFormFieldExists('name')
            ->assertFormFieldExists('nickname');
    }
}
