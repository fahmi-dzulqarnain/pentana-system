<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\Lunches\Pages\CreateLunch;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DatePickerNativeTest extends TestCase
{
    use RefreshDatabase;

    public function test_lunch_form_uses_filament_pickers_not_native_browser_inputs(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(CreateLunch::class)
            ->assertSuccessful()
            ->assertDontSee('type="date"', false)            // no native date input
            ->assertDontSee('type="datetime-local"', false); // no native datetime input
    }
}
