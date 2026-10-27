<?php

namespace Tests\Feature\Localisation;

use App\Filament\Widgets\EmailBudgetStats;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EmailBudgetWidgetLocalisedTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_renders_in_the_admins_language(): void
    {
        config(['app.locales' => 'en,ms,id']);
        $admin = User::factory()->create(['is_admin' => true, 'locale' => 'ms', 'activated_at' => now()]);
        $this->actingAs($admin);
        app()->setLocale('ms');

        Livewire::test(EmailBudgetStats::class)
            ->assertSee('Bajet e-mel')
            ->assertSee('E-mel hari ini')
            ->assertSee('tiada yang menunggu')
            ->assertDontSee('Email today');
    }

    public function test_widget_english_is_unchanged(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'activated_at' => now()]));

        Livewire::test(EmailBudgetStats::class)
            ->assertSee('Email budget')
            ->assertSee('Email today')
            ->assertSee('100 remaining today')
            ->assertSee('nothing waiting');
    }
}
