<?php

namespace Tests\Feature\Activity;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityFormLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function createPage(): string
    {
        return $this->actingAs(User::factory()->admin()->create(['activated_at' => now()]))
            ->get('/account/activities/create')->assertOk()->getContent();
    }

    public function test_fields_are_grouped_and_dates_follow_the_events_timeline(): void
    {
        $html = $this->createPage();

        $heading = function (string $title) use ($html): int|false {
            return preg_match('/fi-section-header-heading[^>]*>\s*'.$title.'\s*</', $html, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
        };
        $order = [
            $heading('Details'), strpos($html, 'data.title'), strpos($html, 'data.description'), strpos($html, 'data.location'),
            $heading('When'), strpos($html, 'data.starts_at'), strpos($html, 'data.ends_at'), strpos($html, 'data.registration_closes_at'),
            $heading('Registration'), strpos($html, 'data.capacity'), strpos($html, 'data.registration_fields'),
        ];

        $this->assertNotContains(false, $order);
        $this->assertSame($order, collect($order)->sort()->values()->all());
    }

    public function test_the_description_editor_offers_only_what_an_event_blurb_needs(): void
    {
        $html = $this->createPage();

        foreach (['toggleBold', 'toggleItalic', 'toggleBulletList', 'toggleOrderedList'] as $command) {
            $this->assertStringContainsString($command, $html, "missing {$command}");
        }
        foreach (['toggleUnderline', 'toggleSuperscript', 'toggleHeading', 'setTextAlign', 'toggleCodeBlock', 'insertTable'] as $command) {
            $this->assertStringNotContainsString($command, $html, "unexpected {$command}");
        }
    }

    public function test_member_page_strips_scripts_from_descriptions(): void
    {
        Activity::factory()->create([
            'status' => 'published',
            'starts_at' => now()->addWeek(),
            'description' => '<p>Bring <strong>water</strong>.</p><script>alert(1)</script>',
        ]);

        $this->actingAs(User::factory()->create(['activated_at' => now()]))
            ->get('/account/my-activities')
            ->assertOk()
            ->assertSee('<strong>water</strong>', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
