<?php

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertStatus(401);
    }

    public function test_it_aggregates_the_members_summary(): void
    {
        $member = User::factory()->create();

        // Bills: a $100 bill with $30 verified -> $70 outstanding, 1 unpaid.
        FeePeriod::create(['user_id' => $member->id, 'month_date' => '2026-06-01', 'amount_due' => 100, 'status' => 'unpaid']);
        $verified = PaymentProof::create([
            'user_id' => $member->id, 'proof_image_path' => 'receipts/a.jpg', 'amount_claimed' => 30, 'status' => 'pending',
        ]);
        app(FinanceService::class)->verifyAndAllocate($verified, 30);

        // A still-pending proof awaiting review.
        PaymentProof::create([
            'user_id' => $member->id, 'proof_image_path' => 'receipts/b.jpg', 'amount_claimed' => 20, 'status' => 'pending',
        ]);

        // Next lunch: upcoming published, not yet responded.
        $lunch = Lunch::factory()->create(['status' => 'published', 'date' => today()->addDay()]);

        // Next activity: registered; plus one other open activity available to join.
        $registered = Activity::factory()->create(['status' => 'published', 'starts_at' => now()->addWeek(), 'title' => 'Hiking']);
        ActivityRegistration::factory()->create([
            'activity_id' => $registered->id, 'user_id' => $member->id, 'status' => 'registered',
        ]);
        Activity::factory()->create(['status' => 'published', 'starts_at' => now()->addWeeks(2)]);

        // Noise that must NOT count: another member's everything.
        $other = User::factory()->create();
        FeePeriod::create(['user_id' => $other->id, 'month_date' => '2026-06-01', 'amount_due' => 500, 'status' => 'unpaid']);

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.bills.total_outstanding', '70.00')
            ->assertJsonPath('data.bills.unpaid_count', 1)
            ->assertJsonPath('data.pending_proofs_count', 1)
            ->assertJsonPath('data.next_lunch.id', $lunch->id)
            ->assertJsonPath('data.next_lunch.responded', false)
            ->assertJsonPath('data.next_activity.id', $registered->id)
            ->assertJsonPath('data.next_activity.my_status', 'registered')
            ->assertJsonPath('data.open_activities_count', 1);
    }

    public function test_next_lunch_prefers_an_unresponded_open_lunch_over_an_earlier_responded_one(): void
    {
        $member = User::factory()->create();

        // Earlier lunch, already voted on.
        $responded = Lunch::factory()->create([
            'status' => 'published', 'date' => today()->addDay(), 'deadline' => now()->addDays(2),
        ]);
        LunchResponse::factory()->create(['lunch_id' => $responded->id, 'user_id' => $member->id]);

        // Later lunch, still needs a vote — this is the actionable one.
        $needsVote = Lunch::factory()->create([
            'status' => 'published', 'date' => today()->addDays(3), 'deadline' => now()->addDays(4),
        ]);

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.next_lunch.id', $needsVote->id)
            ->assertJsonPath('data.next_lunch.responded', false)
            ->assertJsonPath('data.next_lunch.is_open', true);
    }

    public function test_next_lunch_falls_back_to_the_next_lunch_with_its_menu_when_all_responded(): void
    {
        $member = User::factory()->create();

        $lunch = Lunch::factory()->create([
            'status' => 'published', 'date' => today()->addDay(), 'deadline' => now()->addDays(2),
        ]);
        LunchResponse::factory()->create(['lunch_id' => $lunch->id, 'user_id' => $member->id]);
        $lunch->load('catererMeal');

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.next_lunch.id', $lunch->id)
            ->assertJsonPath('data.next_lunch.responded', true)
            ->assertJsonPath('data.next_lunch.menu', $lunch->catererMeal?->name);
    }

    public function test_empty_state_returns_nulls_and_zeroes(): void
    {
        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.next_lunch', null)
            ->assertJsonPath('data.next_activity', null)
            ->assertJsonPath('data.open_activities_count', 0)
            ->assertJsonPath('data.pending_proofs_count', 0)
            ->assertJsonPath('data.bills.total_outstanding', '0.00');
    }
}
