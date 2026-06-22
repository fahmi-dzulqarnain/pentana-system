<?php

namespace Tests\Feature\Lunch;

use App\Models\Lunch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseLunchOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_closes_only_past_deadline_published_lunches(): void
    {
        $pastPublished = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->subHour()]);
        $futurePublished = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addHour()]);
        $pastDraft = Lunch::factory()->create(['status' => 'draft', 'deadline' => now()->subHour()]);

        $this->artisan('app:close-lunch-orders')->assertSuccessful();

        $this->assertSame('closed', $pastPublished->fresh()->status);
        $this->assertSame('published', $futurePublished->fresh()->status);
        $this->assertSame('draft', $pastDraft->fresh()->status);
    }
}
