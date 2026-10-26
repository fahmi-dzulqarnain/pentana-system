<?php

namespace Tests\Feature\Finance;

use App\Enums\StatementLineOutcome;
use App\Filament\Resources\BankStatements\BankStatementImportResource;
use App\Filament\Resources\BankStatements\Pages\ListBankStatementImports;
use App\Filament\Resources\BankStatements\Pages\ViewBankStatementImport;
use App\Filament\Resources\BankStatements\RelationManagers\LinesRelationManager;
use App\Models\Payment\BankStatementImport;
use App\Models\Payment\BankStatementLine;
use App\Models\User;
use App\Services\BankStatements\StatementTextExtractor;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BankStatementScreensTest extends TestCase
{
    use RefreshDatabase;

    private string $text;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        Storage::fake('local');
        Filament::setCurrentPanel(Filament::getPanel('account'));

        $this->text = file_get_contents(base_path('tests/Fixtures/bank-statements/cimb-synthetic.txt'));

        $this->app->bind(StatementTextExtractor::class, fn () => new class($this) implements StatementTextExtractor
        {
            public function __construct(private BankStatementScreensTest $test) {}

            public function extract(string $pdfPath): string
            {
                return $this->test->text();
            }
        });
    }

    public function text(): string
    {
        return $this->text;
    }

    private function upload(): array
    {
        return ['file' => UploadedFile::fake()->create('s.pdf', 10, 'application/pdf')];
    }

    public function test_treasurer_imports_a_statement_from_the_list_page(): void
    {
        $treasurer = User::factory()->treasurer()->create(['activated_at' => now()]);
        $this->actingAs($treasurer);

        Livewire::test(ListBankStatementImports::class)
            ->callAction('importStatement', data: $this->upload())
            ->assertHasNoActionErrors()
            ->assertNotified()
            ->assertRedirect(BankStatementImportResource::getUrl('view', ['record' => BankStatementImport::first()]));

        $this->assertSame(1, BankStatementImport::count());
        $this->assertSame($treasurer->id, BankStatementImport::first()->uploaded_by);
        $this->assertSame([], Storage::disk('local')->allFiles('bank-statements'));
    }

    public function test_unreconciled_statement_shows_the_reason(): void
    {
        $this->text = str_replace('1,699.90', '1,699.91', $this->text);
        $this->actingAs(User::factory()->treasurer()->create(['activated_at' => now()]));

        Livewire::test(ListBankStatementImports::class)
            ->callAction('importStatement', data: $this->upload())
            ->assertNotified(Notification::make()->title(__('Balance does not reconcile on :date', ['date' => '28/09/2024']))->danger())
            ->assertNoRedirect();

        $this->assertSame(0, BankStatementImport::count());
    }

    public function test_super_admin_without_treasurer_cannot_open_the_resource(): void
    {
        $url = BankStatementImportResource::getUrl('index');

        foreach ([User::factory()->superAdmin()->create(['activated_at' => now()]), User::factory()->admin()->create(['activated_at' => now()]), User::factory()->create(['activated_at' => now()])] as $user) {
            $this->actingAs($user)->get($url)->assertForbidden();
            $this->assertFalse(BankStatementImportResource::canAccess());
        }

        $this->actingAs(User::factory()->treasurer()->create(['activated_at' => now()]))->get($url)->assertOk();
        $this->assertTrue(BankStatementImportResource::canAccess());
    }

    public function test_import_action_refuses_a_non_treasurer_server_side(): void
    {
        $this->actingAs(User::factory()->treasurer()->create(['activated_at' => now()]));
        $component = Livewire::test(ListBankStatementImports::class);

        $this->actingAs(User::factory()->superAdmin()->create(['activated_at' => now()]));
        // The action is hidden and unrunnable for the new user; the handler also aborts 403 on its own.
        $component->assertActionHidden('importStatement');

        $this->assertSame(0, BankStatementImport::count());
    }

    public function test_lines_can_be_filtered_by_outcome(): void
    {
        $this->actingAs(User::factory()->treasurer()->create(['activated_at' => now()]));
        $import = BankStatementImport::factory()->create();
        $noCode = BankStatementLine::factory()->for($import, 'import')->create(['outcome' => StatementLineOutcome::NoCode]);
        $review = BankStatementLine::factory()->for($import, 'import')->create(['outcome' => StatementLineOutcome::NeedsReview]);

        Livewire::test(LinesRelationManager::class, ['ownerRecord' => $import, 'pageClass' => ViewBankStatementImport::class])
            ->assertCanSeeTableRecords([$noCode, $review])
            ->filterTable('outcome', 'no_code')
            ->assertCanSeeTableRecords([$noCode])
            ->assertCanNotSeeTableRecords([$review]);
    }
}
