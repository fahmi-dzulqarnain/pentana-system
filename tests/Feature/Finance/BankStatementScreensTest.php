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
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Tests\TestCase;

class BankStatementScreensTest extends TestCase
{
    use RefreshDatabase;

    private string $text;

    /** @var list<string> paths the importer was handed */
    public array $readPaths = [];

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
                $this->test->readPaths[] = $pdfPath;

                return $this->test->text();
            }
        });
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * Livewire's temporary uploads (in tests, the tmp-for-tests disk, faked once per run),
     * minus the .json metadata sidecars Livewire's test uploads write next to each file.
     */
    private function temporaryUploads(): array
    {
        return array_values(array_filter(FileUploadConfiguration::storage()->allFiles(), fn (string $path) => ! str_ends_with($path, '.json')));
    }

    private function upload(): array
    {
        return ['file' => UploadedFile::fake()->create('s.pdf', 10, 'application/pdf')];
    }

    public function test_treasurer_imports_a_statement_from_the_list_page(): void
    {
        $treasurer = User::factory()->treasurer()->create(['activated_at' => now()]);
        $this->actingAs($treasurer);
        $before = $this->temporaryUploads();

        Livewire::test(ListBankStatementImports::class)
            ->callAction('importStatement', data: $this->upload())
            ->assertHasNoActionErrors()
            ->assertNotified()
            ->assertRedirect(BankStatementImportResource::getUrl('view', ['record' => BankStatementImport::first()]));

        $this->assertSame(1, BankStatementImport::count());
        $this->assertSame($treasurer->id, BankStatementImport::first()->uploaded_by);
        // The importer read Livewire's temporary upload itself, never a copy stored on a disk ...
        $this->assertCount(1, $this->readPaths);
        $this->assertStringStartsWith(FileUploadConfiguration::storage()->path('livewire-tmp/'), $this->readPaths[0]);
        $this->assertSame([], Storage::disk('local')->allFiles());
        // ... and that temporary upload is gone.
        $this->assertSame($before, $this->temporaryUploads());
    }

    public function test_unreconciled_statement_shows_the_reason(): void
    {
        $this->text = str_replace('1,699.90', '1,699.91', $this->text);
        $this->actingAs(User::factory()->treasurer()->create(['activated_at' => now()]));
        $before = $this->temporaryUploads();

        Livewire::test(ListBankStatementImports::class)
            ->callAction('importStatement', data: $this->upload())
            ->assertNotified(Notification::make()->title(__('Balance does not reconcile on :date', ['date' => '28/09/2024']))->danger())
            ->assertNoRedirect();

        $this->assertSame(0, BankStatementImport::count());
        $this->assertSame($before, $this->temporaryUploads());
    }

    public function test_duplicate_statement_is_refused_and_its_upload_deleted(): void
    {
        $this->actingAs(User::factory()->treasurer()->create(['activated_at' => now()]));
        $file = UploadedFile::fake()->createWithContent('s.pdf', 'same statement');
        BankStatementImport::factory()->create(['file_sha256' => hash('sha256', 'same statement')]);
        $before = $this->temporaryUploads();

        Livewire::test(ListBankStatementImports::class)
            ->callAction('importStatement', data: ['file' => $file])
            ->assertNotified(Notification::make()->title(__('This statement has already been imported.'))->danger())
            ->assertNoRedirect();

        $this->assertSame(1, BankStatementImport::count());
        $this->assertSame($before, $this->temporaryUploads());
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

    public function test_a_forged_file_value_is_refused_and_nothing_is_touched(): void
    {
        Storage::disk('local')->put('receipts/x.pdf', 'keep');
        $this->actingAs(User::factory()->treasurer()->create(['activated_at' => now()]));

        // Only a signed Livewire temporary upload becomes a file object; any other value is refused.
        foreach (['receipts/x.pdf', '../.env', 'livewire-tmp/x.pdf'] as $forged) {
            Livewire::test(ListBankStatementImports::class)
                ->callAction('importStatement', data: ['file' => [(string) Str::uuid() => $forged]]);
        }

        $this->assertSame(0, BankStatementImport::count());
        Storage::disk('local')->assertExists('receipts/x.pdf');
    }

    public function test_temporary_upload_is_removed_when_the_importer_fails_unexpectedly(): void
    {
        $this->app->bind(StatementTextExtractor::class, fn () => new class implements StatementTextExtractor
        {
            public function extract(string $pdfPath): string
            {
                throw new \RuntimeException('boom');
            }
        });
        $this->actingAs(User::factory()->treasurer()->create(['activated_at' => now()]));
        $before = $this->temporaryUploads();

        try {
            Livewire::test(ListBankStatementImports::class)
                ->callAction('importStatement', data: $this->upload());
            $this->fail('Expected the exception to surface.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($before, $this->temporaryUploads());
        $this->assertSame(0, BankStatementImport::count());
    }

    public function test_view_page_shows_new_and_already_imported_line_counts(): void
    {
        $this->actingAs(User::factory()->treasurer()->create(['activated_at' => now()]));
        $import = BankStatementImport::factory()->create(['lines_new' => 4, 'lines_duplicate' => 731]);

        Livewire::test(ViewBankStatementImport::class, ['record' => $import->getRouteKey()])
            ->assertSee(__('New lines'))
            ->assertSee(__('Already imported'))
            ->assertSeeInOrder([__('Already imported'), '731']);
    }

    public function test_list_shows_live_outcome_counts_from_each_imports_own_lines(): void
    {
        $this->actingAs(User::factory()->treasurer()->create(['activated_at' => now()]));
        $import = BankStatementImport::factory()->create();
        $other = BankStatementImport::factory()->create();
        foreach ([StatementLineOutcome::AutoVerified, StatementLineOutcome::AutoVerified, StatementLineOutcome::Confirmed, StatementLineOutcome::NeedsReview, StatementLineOutcome::NoProof, StatementLineOutcome::NoCode] as $outcome) {
            BankStatementLine::factory()->for($import, 'import')->create(['outcome' => $outcome]);
        }
        BankStatementLine::factory()->for($other, 'import')->create(['outcome' => StatementLineOutcome::AutoVerified]);

        Livewire::test(ListBankStatementImports::class)
            ->assertTableColumnStateSet('auto_verified_count', 2, $import)
            ->assertTableColumnStateSet('confirmed_count', 1, $import)
            ->assertTableColumnStateSet('to_review_count', 3, $import)
            ->assertTableColumnStateSet('auto_verified_count', 1, $other)
            ->assertTableColumnStateSet('to_review_count', 0, $other);

        Livewire::test(ViewBankStatementImport::class, ['record' => $import->getRouteKey()])
            ->assertSeeInOrder([__('Auto-verified'), '2', __('Confirmed'), '1', __('To review'), '3']);
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
