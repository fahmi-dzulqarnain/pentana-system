<?php

namespace Tests\Feature\Birthday;

use App\Filament\Resources\AutoEmails\Pages\CreateAutoEmail;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AutoEmailTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_birthday_type_shows_active_toggle_and_hides_schedule(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(CreateAutoEmail::class)
            ->assertFormFieldExists('type')
            ->set('data.type', 'birthday')
            ->assertFormFieldExists('is_active')
            ->assertFormFieldIsHidden('send_at')
            ->assertFormFieldIsHidden('status');
    }

    public function test_one_off_type_shows_schedule_and_hides_active(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(CreateAutoEmail::class)
            ->set('data.type', 'one_off')
            ->assertFormFieldExists('send_at')
            ->assertFormFieldIsHidden('is_active');
    }
}
