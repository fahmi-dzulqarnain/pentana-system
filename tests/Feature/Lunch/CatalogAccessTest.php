<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\MealOptions\MealOptionResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_meal_options_are_admin_only(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(MealOptionResource::canAccess());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(MealOptionResource::canAccess());
    }
}
