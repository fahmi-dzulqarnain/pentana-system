<?php

namespace Tests\Feature;

use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\AutoEmails\AutoEmailResource;
use App\Filament\Resources\LunchPlans\LunchPlanResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelfareActivityAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_cannot_access_email_sender_or_activity_management(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(AutoEmailResource::canAccess());
        $this->assertFalse(ActivityResource::canAccess());
    }

    public function test_admins_can_access_all_new_resources(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->assertTrue(LunchPlanResource::canAccess());
        $this->assertTrue(AutoEmailResource::canAccess());
        $this->assertTrue(ActivityResource::canAccess());
    }

    public function test_members_can_view_lunch_but_not_manage_it(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertTrue(LunchPlanResource::canAccess());
        $this->assertFalse(LunchPlanResource::canCreate());
    }

    public function test_admins_can_manage_lunch(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->assertTrue(LunchPlanResource::canCreate());
    }

    public function test_lunch_navigation_label_depends_on_role(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->assertSame('Lunch Planner', LunchPlanResource::getNavigationLabel());

        $this->actingAs(User::factory()->create());
        $this->assertSame('Lunch', LunchPlanResource::getNavigationLabel());
    }
}
