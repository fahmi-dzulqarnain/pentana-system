<?php

namespace Tests\Feature\Finance;

use App\Filament\Pages\FinanceSummary;
use App\Models\Payment\FeePeriod;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceSummaryExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('account'));
        Carbon::setTestNow('2026-10-19 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owing(string $name, float $due = 50): User
    {
        $user = User::factory()->create(['name' => $name]);
        FeePeriod::create([
            'user_id' => $user->id,
            'month_date' => '2026-03-01',
            'amount_due' => $due,
            'status' => 'unpaid',
        ]);

        return $user;
    }

    /** Runs the export and returns the downloaded body. */
    private function export(?callable $prepare = null): string
    {
        $this->actingAs(User::factory()->treasurer()->create(['activated_at' => now()]));

        $page = Livewire::test(FinanceSummary::class);
        if ($prepare) {
            $page = $prepare($page);
        }
        $page->callAction('export');

        $download = $page->effects['download'] ?? null;
        $this->assertNotNull($download, 'Export did not return a download.');
        $this->lastDownload = $download;

        return base64_decode($download['content']);
    }

    private array $lastDownload = [];

    public function test_export_respects_filter_and_search(): void
    {
        $this->owing('Ahmad Owing');
        $this->owing('Siti Owing');
        User::factory()->create(['name' => 'Zero Owing']);

        $rows = array_filter(explode("\n", $this->export()));
        $this->assertCount(3, $rows); // header + two owing members
        $this->assertStringNotContainsString('Zero Owing', implode("\n", $rows));

        $rows = array_filter(explode("\n", $this->export(fn ($p) => $p->searchTable('Ahmad'))));
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('Ahmad Owing', $rows[1]);
    }

    public function test_export_quotes_awkward_names_and_starts_with_bom(): void
    {
        $this->owing('Lim, "Jay" 林');

        $body = $this->export();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $lines = explode("\n", substr($body, 3));
        $this->assertCount(3, $lines); // header, member, empty after trailing newline
        $this->assertSame('', $lines[2]);
        $this->assertSame('Lim, "Jay" 林', str_getcsv($lines[1])[0]);
        $this->assertSame('50.00', str_getcsv($lines[1])[3]);
        $this->assertSame('2026-03', str_getcsv($lines[1])[5]);
        $this->assertSame('No', str_getcsv($lines[1])[7]);
    }

    public function test_export_filename_and_columns(): void
    {
        Bus::fake();
        $this->owing('Ahmad Owing');

        $body = $this->export();

        $this->assertSame('finance-summary-2026-10-19.csv', $this->lastDownload['name']);
        $header = str_getcsv(explode("\n", substr($body, 3))[0]);
        $this->assertSame(
            ['Name', 'Email', 'Category', 'Owes (MYR)', 'Unpaid months', 'Oldest unpaid', 'Credit (MYR)', 'Paid this month'],
            $header
        );
        Bus::assertNothingDispatched();
    }

    public function test_non_finance_users_cannot_export(): void
    {
        $this->actingAs(User::factory()->admin()->create(['activated_at' => now()]));

        Livewire::test(FinanceSummary::class)->assertForbidden();
    }
}
