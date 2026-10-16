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

    public function test_a_birthday_template_can_leave_subject_and_body_blank(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(CreateAutoEmail::class)
            ->fillForm(['type' => 'birthday', 'subject' => null, 'body' => null, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('auto_emails', ['type' => 'birthday', 'subject' => null]);
    }

    public function test_a_one_off_email_still_needs_a_subject_and_body(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(CreateAutoEmail::class)
            ->fillForm(['type' => 'one_off', 'subject' => null, 'body' => null])
            ->call('create')
            ->assertHasFormErrors(['subject' => 'required', 'body']);
    }
}
