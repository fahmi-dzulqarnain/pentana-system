<?php

namespace Tests\Feature;

use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\AutoEmails\AutoEmailResource;
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

        $this->assertTrue(AutoEmailResource::canAccess());
        $this->assertTrue(ActivityResource::canAccess());
    }
}
