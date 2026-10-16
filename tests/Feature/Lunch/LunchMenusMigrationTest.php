<?php

namespace Tests\Feature\Lunch;

use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\MealOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LunchMenusMigrationTest extends TestCase
{
    use RefreshDatabase;

    private int $lunchId;

    private int $catererId;

    private int $mealId;

    private int $optA;

    private int $optB;

    private int $optC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:rollback', ['--step' => 2])->assertExitCode(0);

        $this->catererId = Caterer::factory()->create()->id;
        $this->mealId = CatererMeal::factory()->create(['caterer_id' => $this->catererId])->id;
        $this->optA = MealOption::factory()->create()->id;
        $this->optB = MealOption::factory()->create()->id;
        $this->optC = MealOption::factory()->create()->id;

        $now = now();
        $this->lunchId = DB::table('lunches')->insertGetId([
            'date' => '2026-11-02', 'caterer_id' => $this->catererId, 'caterer_meal_id' => $this->mealId,
            'deadline' => '2026-11-01 10:00:00', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([$this->optA, $this->optB] as $opt) {
            DB::table('lunch_options')->insert([
                'lunch_id' => $this->lunchId, 'meal_option_id' => $opt, 'enabled' => true,
                'default_count' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ([[$this->optA], [null], [$this->optC]] as [$opt]) {
            DB::table('lunch_responses')->insert([
                'lunch_id' => $this->lunchId, 'user_id' => User::factory()->create()->id,
                'meal_option_id' => $opt, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->artisan('migrate')->assertExitCode(0);
    }

    public function test_existing_lunch_becomes_a_one_menu_day(): void
    {
        $menus = DB::table('lunch_menus')->where('lunch_id', $this->lunchId)->get();

        $this->assertCount(1, $menus);
        $this->assertSame($this->catererId, (int) $menus[0]->caterer_id);
        $this->assertSame($this->mealId, (int) $menus[0]->caterer_meal_id);
        $this->assertSame(2, DB::table('lunch_options')->where('lunch_menu_id', $menus[0]->id)->count());
    }

    public function test_responses_get_their_lunch_option_id(): void
    {
        $optionA = DB::table('lunch_options')->where('meal_option_id', $this->optA)->value('id');

        $this->assertSame($optionA, DB::table('lunch_responses')->where('meal_option_id', $this->optA)->value('lunch_option_id'));

        $notAttending = DB::table('lunch_responses')->whereNull('meal_option_id')->first();
        $this->assertNull($notAttending->lunch_option_id);
    }

    public function test_orphan_response_is_kept_and_still_counts(): void
    {
        $orphan = DB::table('lunch_responses')->where('meal_option_id', $this->optC)->first();

        $this->assertNotNull($orphan);
        $this->assertNull($orphan->lunch_option_id);
        $this->assertSame(2, Lunch::with('responses')->find($this->lunchId)->attendingCount());
    }

    public function test_rolling_back_restores_the_single_menu_shape(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 2])->assertExitCode(0);

        $lunch = DB::table('lunches')->find($this->lunchId);
        $this->assertSame($this->catererId, (int) $lunch->caterer_id);
        $this->assertSame($this->mealId, (int) $lunch->caterer_meal_id);
        $this->assertSame(2, DB::table('lunch_options')->where('lunch_id', $this->lunchId)->count());
        $this->assertSame(3, DB::table('lunch_responses')->where('lunch_id', $this->lunchId)->count());
    }
}
