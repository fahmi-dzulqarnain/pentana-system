<?php

namespace Tests\Feature\Lunch;

use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use App\Services\LunchAgenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The week from the design mockups: Wednesday 28 Oct 2026, 10:00 in Kuala Lumpur. */
class LunchAgendaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-28 10:00', 'Asia/Kuala_Lumpur'));
        $this->user = User::factory()->create(['time_format' => null]);
        $this->actingAs($this->user);
    }

    private function lunch(string $date, string $deadline, ?string $pick = null): Lunch
    {
        $lunch = Lunch::factory()->create(['date' => $date, 'status' => 'published', 'deadline' => Carbon::parse($deadline, 'Asia/Kuala_Lumpur')]);
        $option = LunchOption::factory()->create(['lunch_id' => $lunch->id, 'meal_option_id' => MealOption::factory()->create(['name' => $pick ?? 'Salad'])->id, 'enabled' => true]);
        if ($pick !== null) {
            LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => $this->user->id, 'lunch_option_id' => $option->id, 'meal_option_id' => $option->meal_option_id]);
        }

        return $lunch;
    }

    private function agenda(): array
    {
        $lunches = Lunch::with(['menus.caterer', 'menus.catererMeal', 'menus.options.mealOption'])->get();
        $responses = LunchResponse::with('mealOption')->where('user_id', $this->user->id)->get()->keyBy('lunch_id');

        return app(LunchAgenda::class)->build($lunches, $responses);
    }

    private function rows(): array
    {
        return collect($this->agenda()['sections'])->flatMap(fn ($s) => $s['rows'])->keyBy('lunch_id')->all();
    }

    public function test_banner_counts_pending_days_and_targets_the_soonest(): void
    {
        $this->lunch('2026-11-02', '2026-11-01 16:00');
        $thu = $this->lunch('2026-10-29', '2026-10-28 16:00');

        $banner = $this->agenda()['banner'];
        $this->assertSame('2 lunches need your vote', $banner['title']);
        $this->assertSame(['Thu closes today 4:00 PM · in 6 h', 'Mon closes Sun 4:00 PM'], $banner['lines']);
        $this->assertSame('Vote for Thu', $banner['action']);
        $this->assertSame($thu->id, $banner['lunch_id']);
    }

    public function test_rows_say_what_each_day_needs(): void
    {
        $past = $this->lunch('2026-10-26', '2026-10-25 16:00', 'Extra sambal');
        $none = $this->lunch('2026-10-27', '2026-10-26 16:00');
        $today = $this->lunch('2026-10-28', '2026-10-27 16:00', 'Grilled salad');
        $open = $this->lunch('2026-10-29', '2026-10-28 16:00');
        $ordered = $this->lunch('2026-10-30', '2026-10-29 16:00', 'Regular');
        $rows = $this->rows();

        $this->assertSame(['past', 'Extra sambal'], [$rows[$past->id]['kind'], $rows[$past->id]['title']]);
        $this->assertSame("You didn't order", $rows[$none->id]['title']);
        $this->assertTrue($rows[$today->id]['is_today']);
        $this->assertSame("Today · You're having", $rows[$today->id]['eyebrow']);
        $this->assertSame(['WED', '28'], [$rows[$today->id]['weekday'], $rows[$today->id]['day']]);
        $this->assertSame(['needs_vote', 'vote', true], [$rows[$open->id]['kind'], $rows[$open->id]['action'], $rows[$open->id]['urgent']]);
        $this->assertSame('Closes today 4:00 PM · in 6 h', $rows[$open->id]['subtitle']);
        $this->assertSame(['ordered', 'change', 'Change by tomorrow 4:00 PM'], [$rows[$ordered->id]['kind'], $rows[$ordered->id]['action'], $rows[$ordered->id]['subtitle']]);
    }

    public function test_sections_split_the_weeks(): void
    {
        $this->lunch('2026-10-30', '2026-10-29 16:00');
        $this->lunch('2026-11-02', '2026-11-01 16:00');
        $this->lunch('2026-11-10', '2026-11-09 16:00');

        $this->assertSame(['This week', 'Next week', 'Later'], array_column($this->agenda()['sections'], 'title'));
        $later = collect($this->agenda()['sections'])->firstWhere('title', 'Later')['rows'][0];
        $this->assertSame('Closes Mon 9 Nov, 4:00 PM', $later['subtitle']);
    }

    public function test_countdown_turns_to_minutes_in_the_last_hour_and_closes_after_the_deadline(): void
    {
        $thu = $this->lunch('2026-10-29', '2026-10-28 16:00');

        $this->travelTo(Carbon::parse('2026-10-28 15:15', 'Asia/Kuala_Lumpur'));
        $this->assertSame('Closes today 4:00 PM · in 45 min', $this->rows()[$thu->id]['subtitle']);

        $this->travelTo(Carbon::parse('2026-10-28 16:01', 'Asia/Kuala_Lumpur'));
        $row = $this->rows()[$thu->id];
        $this->assertSame(['closed', "You didn't order", 'Ordering closed'], [$row['kind'], $row['title'], $row['subtitle']]);
        $this->assertNull($this->agenda()['banner']);
    }

    public function test_indonesian_uses_its_words_and_a_24_hour_clock(): void
    {
        app()->setLocale('id');
        Carbon::setLocale('id');
        $thu = $this->lunch('2026-10-29', '2026-10-28 16:00');

        $this->assertSame('Ditutup hari ini 16:00 · 6 jam lagi', $this->rows()[$thu->id]['subtitle']);
        $this->assertSame('1 makan siang menunggu pilihan Anda', $this->agenda()['banner']['title']);
    }
}
