<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Lunch;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoContentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_votable_and_closed_lunches(): void
    {
        $this->seed(DemoContentSeeder::class);

        $lunches = Lunch::with('options')->get();
        $open = $lunches->first(fn (Lunch $l) => $l->isOpenForVoting());
        $closed = $lunches->first(fn (Lunch $l) => ! $l->isOpenForVoting());

        $this->assertNotNull($open, 'expected a lunch open for voting');
        $this->assertNotNull($closed, 'expected a closed lunch');
        $this->assertGreaterThanOrEqual(2, $open->options->where('enabled', true)->count());
        // options resolve to a named MealOption (what the app shows)
        $this->assertNotNull($open->options->first()->mealOption?->name);
    }

    public function test_it_seeds_open_closed_and_question_activities(): void
    {
        $this->seed(DemoContentSeeder::class);

        $activities = Activity::get();
        $this->assertTrue($activities->contains(fn (Activity $a) => $a->isOpenForRegistration()), 'expected an open activity');
        $this->assertTrue($activities->contains(fn (Activity $a) => ! $a->isOpenForRegistration()), 'expected a closed activity');

        $withQuestions = $activities->first(fn (Activity $a) => ! empty($a->registration_fields));
        $this->assertNotNull($withQuestions, 'expected an activity with a registration form');
        $this->assertArrayHasKey('type', $withQuestions->registration_fields[0]);
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(DemoContentSeeder::class);
        $lunches = Lunch::count();
        $activities = Activity::count();

        $this->seed(DemoContentSeeder::class);

        $this->assertSame($lunches, Lunch::count());
        $this->assertSame($activities, Activity::count());
    }
}
