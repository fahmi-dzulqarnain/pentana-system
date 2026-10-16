<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberEmptyStatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_lunch_page_explains_what_happens_next(): void
    {
        $this->actingAs(User::factory()->create(['activated_at' => now()]))
            ->get('/account/my-lunch')
            ->assertOk()
            ->assertSee('No lunch to order right now')
            ->assertSee('When the next menu is published, it appears here and we will email you and notify you in the app.')
            ->assertDontSee('Plan a lunch');
    }

    public function test_an_empty_activities_page_explains_what_happens_next(): void
    {
        $this->actingAs(User::factory()->create(['activated_at' => now()]))
            ->get('/account/my-activities')
            ->assertOk()
            ->assertSee('No upcoming activities')
            ->assertSee('When an activity is announced, it appears here and we will email you and notify you in the app.')
            ->assertDontSee('Create an activity');
    }

    public function test_admins_get_the_action_that_fills_the_page(): void
    {
        $this->actingAs(User::factory()->admin()->create(['activated_at' => now()]));

        $this->get('/account/my-lunch')->assertOk()->assertSee('Plan a lunch')->assertSee('href="'.url('/account/lunch-calendar').'"', false);
        $this->get('/account/my-activities')->assertOk()->assertSee('Create an activity')->assertSee('href="'.url('/account/activities/create').'"', false);
    }
}
