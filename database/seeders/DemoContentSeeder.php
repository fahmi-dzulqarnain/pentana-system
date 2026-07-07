<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\MealOption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo content for Lunch + Activities — enough to populate the member app's screens
 * (App Store review, manual testing). Idempotent: safe to run more than once.
 *
 *   php artisan db:seed --class=DemoContentSeeder
 *
 * Not wired into DatabaseSeeder on purpose — run it explicitly where you want the data.
 */
class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedLunches();
        $this->seedActivities();
    }

    private function mealOption(string $name): MealOption
    {
        return MealOption::firstOrCreate(['name' => $name], ['is_active' => true, 'sort' => 0]);
    }

    private function seedLunches(): void
    {
        // 1) Open for voting — future deadline, published.
        $dapur = Caterer::firstOrCreate(
            ['name' => 'Dapur Selera'],
            ['contact_name' => 'Aminah', 'phone' => '+60 12-345 6789', 'email' => 'orders@dapurselera.my'],
        );
        $nasiLemak = CatererMeal::firstOrCreate(
            ['caterer_id' => $dapur->id, 'name' => 'Nasi Lemak Royale'],
            ['price' => 12.00],
        );
        $open = Lunch::updateOrCreate(
            ['caterer_meal_id' => $nasiLemak->id],
            [
                'date' => Carbon::now()->next(Carbon::MONDAY)->toDateString(),
                'caterer_id' => $dapur->id,
                'deadline' => Carbon::now()->addDays(2)->setTime(18, 0),
                'status' => 'published',
            ],
        );
        foreach (['Chicken rendang set', 'Beef rendang set', 'Vegetable curry set'] as $name) {
            LunchOption::firstOrCreate(
                ['lunch_id' => $open->id, 'meal_option_id' => $this->mealOption($name)->id],
                ['enabled' => true, 'default_count' => 0],
            );
        }

        // 2) Closed — deadline already passed (shows the "ordering closed" summary).
        $grind = Caterer::firstOrCreate(
            ['name' => 'The Daily Grind'],
            ['contact_name' => 'Ravi', 'phone' => '+60 12-987 6543', 'email' => 'hello@dailygrind.my'],
        );
        $chickenChop = CatererMeal::firstOrCreate(
            ['caterer_id' => $grind->id, 'name' => 'Chicken Chop & Fries'],
            ['price' => 15.00],
        );
        $closed = Lunch::updateOrCreate(
            ['caterer_meal_id' => $chickenChop->id],
            [
                'date' => Carbon::now()->subDays(3)->toDateString(),
                'caterer_id' => $grind->id,
                'deadline' => Carbon::now()->subDays(4)->setTime(18, 0),
                'status' => 'published',
            ],
        );
        foreach (['Chicken chop', 'Grilled fish', 'Mushroom pasta'] as $name) {
            LunchOption::firstOrCreate(
                ['lunch_id' => $closed->id, 'meal_option_id' => $this->mealOption($name)->id],
                ['enabled' => true, 'default_count' => 0],
            );
        }
    }

    private function seedActivities(): void
    {
        // 1) Open, no questions -> "Register" directly.
        Activity::updateOrCreate(
            ['title' => 'Beach Cleanup at Port Dickson'],
            [
                'description' => 'Join the welfare committee for a morning of coastal cleanup. Gloves and bags provided.',
                'starts_at' => Carbon::now()->addDays(9)->setTime(9, 0),
                'ends_at' => Carbon::now()->addDays(9)->setTime(12, 0),
                'location' => 'Teluk Kemang Beach, Port Dickson',
                'capacity' => 30,
                'registration_closes_at' => Carbon::now()->addDays(7)->setTime(18, 0),
                'status' => 'published',
                'registration_fields' => [],
            ],
        );

        // 2) Open, with a dynamic registration form.
        Activity::updateOrCreate(
            ['title' => 'Raya Cooking Workshop'],
            [
                'description' => 'Learn to cook rendang and ketupat with Chef Aminah. Ingredients provided.',
                'starts_at' => Carbon::now()->addDays(12)->setTime(14, 0),
                'ends_at' => Carbon::now()->addDays(12)->setTime(17, 0),
                'location' => 'HQ Pantry, Level 3',
                'capacity' => 15,
                'registration_closes_at' => Carbon::now()->addDays(10)->setTime(18, 0),
                'status' => 'published',
                'registration_fields' => [
                    ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                    ['key' => 'shirt_size', 'label' => 'T-shirt size', 'type' => 'select', 'required' => true, 'options' => ['S', 'M', 'L', 'XL']],
                    ['key' => 'dietary', 'label' => 'Dietary restrictions', 'type' => 'textarea', 'required' => false],
                    ['key' => 'own_apron', 'label' => 'I can bring my own apron', 'type' => 'checkbox', 'required' => false],
                ],
            ],
        );

        // 3) Registration closed (event still upcoming) -> "Registration closed".
        Activity::updateOrCreate(
            ['title' => 'Hiking at Bukit Tabur'],
            [
                'description' => 'A guided sunrise hike along the quartz ridge. Bring water and proper shoes.',
                'starts_at' => Carbon::now()->addDays(3)->setTime(7, 0),
                'ends_at' => Carbon::now()->addDays(3)->setTime(11, 0),
                'location' => 'Bukit Tabur East, Selangor',
                'capacity' => 20,
                'registration_closes_at' => Carbon::now()->subDay()->setTime(18, 0),
                'status' => 'published',
                'registration_fields' => [],
            ],
        );
    }
}
