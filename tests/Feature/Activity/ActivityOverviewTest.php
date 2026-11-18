<?php

namespace Tests\Feature\Activity;

use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActivityOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_excerpt_decodes_entities_and_strips_tags(): void
    {
        $this->assertSame(
            "Tom's & Jerry's party",
            Activity::plainTextExcerpt('<p>Tom&#39;s &amp; Jerry&apos;s&nbsp;<b>party</b></p>'),
        );
    }

    public function test_excerpt_keeps_paragraphs_apart(): void
    {
        $this->assertSame('First. Second.', Activity::plainTextExcerpt('<p>First.</p><p>Second.</p>'));
    }

    public function test_excerpt_cuts_long_text_at_a_word_boundary(): void
    {
        $excerpt = Activity::plainTextExcerpt('<p>'.str_repeat('lorem ipsum ', 40).'</p>');

        $this->assertLessThanOrEqual(200, mb_strlen($excerpt));
        $this->assertStringEndsWith('…', $excerpt);
        $this->assertMatchesRegularExpression('/(lorem|ipsum)…$/', $excerpt);
    }

    public function test_excerpt_is_null_for_image_only_or_empty_html(): void
    {
        $this->assertNull(Activity::plainTextExcerpt('<p><img src="x" data-id="a.jpg"></p>'));
        $this->assertNull(Activity::plainTextExcerpt(null));
        $this->assertNull(Activity::plainTextExcerpt('<p>&nbsp; </p>'));
    }

    public function test_overview_text_prefers_the_overview_and_falls_back_to_the_excerpt(): void
    {
        $withOverview = Activity::factory()->make(['overview' => 'Short blurb', 'description' => '<p>Long body</p>']);
        $without = Activity::factory()->make(['overview' => null, 'description' => '<p>Long body</p>']);

        $this->assertSame('Short blurb', $withOverview->overviewText());
        $this->assertSame('Long body', $without->overviewText());
    }

    public function test_full_description_points_images_at_the_current_public_disk_url(): void
    {
        $activity = Activity::factory()->make([
            'description' => '<p>Hi</p><img src="http://old-host/storage/activity-images/a.jpg" data-id="activity-images/a.jpg" alt="Poster">',
        ]);

        $html = $activity->fullDescriptionHtml();

        $this->assertStringContainsString(Storage::disk('public')->url('activity-images/a.jpg'), $html);
        $this->assertStringNotContainsString('old-host', $html);
        $this->assertStringContainsString('<p>Hi</p>', $html);
        $this->assertNull(Activity::factory()->make(['description' => null])->fullDescriptionHtml());
    }

    public function test_migration_backfills_overview_from_existing_descriptions(): void
    {
        $id = DB::table('activities')->insertGetId([
            'title' => 'Dinner',
            'description' => '<p>Tom&#39;s &amp; friends</p>',
            'overview' => null,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $kept = DB::table('activities')->insertGetId([
            'title' => 'Run',
            'description' => '<p>Body</p>',
            'overview' => 'Written by an admin',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (require database_path('migrations/2026_11_18_000000_add_overview_to_activities_table.php'))->up();

        $this->assertSame("Tom's & friends", DB::table('activities')->find($id)->overview);
        $this->assertSame('Written by an admin', DB::table('activities')->find($kept)->overview);
    }
}
