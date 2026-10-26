<?php

namespace Tests\Feature\Finance;

use App\Enums\StatementLineOutcome;
use App\Models\Payment\BankStatementImport;
use App\Models\Payment\BankStatementLine;
use App\Models\Payment\FeePeriod;
use App\Models\Payment\PaymentProof;
use App\Models\User;
use App\Services\BankStatements\DuplicateStatementException;
use App\Services\BankStatements\MatchResult;
use App\Services\BankStatements\StatementImporter;
use App\Services\BankStatements\StatementMatcher;
use App\Services\BankStatements\StatementParseException;
use App\Services\BankStatements\StatementTextExtractor;
use App\Services\FinanceService;
use App\Services\MemberNotifier;
use App\Support\ReferenceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StatementImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $text;

    private User $treasurer;

    private User $member;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        Notification::fake();
        $this->travelTo(Carbon::parse('2024-09-30 10:00'));

        $this->treasurer = User::factory()->create();
        $this->member = User::factory()->create();

        // The synthetic fixture's codes belong to user 142; rewrite them to a real test member.
        $code = ReferenceCode::for($this->member->id);
        $this->text = str_replace(['ptn 0142 8', 'PTN01428'], [strtolower($code), $code], $this->fixture());

        $this->app->bind(StatementTextExtractor::class, fn () => new class($this) implements StatementTextExtractor
        {
            public function __construct(private StatementImporterTest $test) {}

            public function extract(string $pdfPath): string
            {
                return $this->test->text();
            }
        });
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'file_exists'));
        parent::tearDown();
    }

    public function text(): string
    {
        return $this->text;
    }

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/bank-statements/cimb-synthetic.txt'));
    }

    private function pdf(string $contents = 'dummy'): string
    {
        return $this->files[] = tap(tempnam(sys_get_temp_dir(), 'stmt'), fn ($path) => file_put_contents($path, $contents));
    }

    private function import(string $path)
    {
        return app(StatementImporter::class)->import($path, $this->treasurer);
    }

    /** The statement plus one newer RM 20.00 credit, balances and summary kept consistent. */
    private function extendedText(): string
    {
        $top = '28/09/2024       DUITNOW TO ACCOUNT                        800000005                                     1,050.00         1,699.90';
        $this->assertStringContainsString($top, $this->text);
        $extra = str_replace(['28/09/2024', '800000005', '1,050.00', '1,699.90'], ['29/09/2024', '800000006', '   20.00', '1,719.90'], $top);
        $text = str_replace($top, $extra."\n                 extra\n\n".$top, $this->text);
        $text = preg_replace('/\b5(\s+500\.10\s+)1,200\.00/', '6${1}1,220.00', $text, -1, $count);
        $this->assertSame(1, $count);

        return $text;
    }

    /** The statement with its newest credit (28/09, ref 800000005, RM 1,050.00) appearing twice. */
    private function twinCreditText(): string
    {
        $top = '28/09/2024       DUITNOW TO ACCOUNT                        800000005                                     1,050.00         1,699.90';
        $this->assertStringContainsString($top, $this->text);
        $twin = str_replace('1,699.90', '2,749.90', $top);
        $text = str_replace($top, $twin."\n                 Pentana fee whole year\n\n".$top, $this->text);
        $text = preg_replace('/\b5(\s+500\.10\s+)1,200\.00/', '6${1}2,250.00', $text, -1, $count);
        $this->assertSame(1, $count);

        return $text;
    }

    private function pendingProof(float $amount): PaymentProof
    {
        $proof = PaymentProof::create([
            'user_id' => $this->member->id,
            'proof_image_path' => 'receipts/r.jpg',
            'amount_claimed' => $amount,
            'status' => 'pending',
        ]);

        $proof->forceFill(['created_at' => Carbon::parse('2024-08-30 09:00')])->save();

        return $proof;
    }

    public function test_imports_credits_and_records_counts(): void
    {
        $path = $this->pdf();

        $import = $this->import($path);

        $this->assertSame(5, BankStatementLine::count());
        $this->assertSame(5, $import->lines_new);
        $this->assertSame(0, $import->lines_duplicate);
        $this->assertSame('2024-09-01', $import->statement_from->toDateString());
        $this->assertSame('2024-09-28', $import->statement_to->toDateString());
        $this->assertSame($this->treasurer->id, $import->uploaded_by);
        $this->assertSame(hash('sha256', 'dummy'), $import->file_sha256);
        $this->assertSame(5, $import->lines()->count());
        $counts = BankStatementImport::withOutcomeCounts()->findOrFail($import->id);
        $this->assertSame(5, $counts->auto_verified_count + $counts->confirmed_count + $counts->to_review_count);
        $this->assertSame(5, $import->matchResult->autoVerified + $import->matchResult->confirmed + $import->matchResult->unmatched);
        $this->assertFileDoesNotExist($path);
    }

    public function test_same_file_twice_is_refused(): void
    {
        $this->import($this->pdf());
        $second = $this->pdf();

        try {
            $this->import($second);
            $this->fail('Expected DuplicateStatementException.');
        } catch (DuplicateStatementException) {
        }

        $this->assertSame(1, BankStatementImport::count());
        $this->assertFileDoesNotExist($second);
    }

    public function test_concurrent_import_of_the_same_file_is_refused_as_a_duplicate(): void
    {
        // The other upload commits between our hash check and our insert (here: while the text is extracted).
        $this->app->bind(StatementTextExtractor::class, fn () => new class($this) implements StatementTextExtractor
        {
            public function __construct(private StatementImporterTest $test) {}

            public function extract(string $pdfPath): string
            {
                BankStatementImport::factory()->create(['file_sha256' => hash('sha256', 'dummy')]);

                return $this->test->text();
            }
        });
        $path = $this->pdf();

        try {
            $this->import($path);
            $this->fail('Expected DuplicateStatementException.');
        } catch (DuplicateStatementException) {
        }

        $this->assertSame(1, BankStatementImport::count());
        $this->assertSame(0, BankStatementLine::count());
        $this->assertFileDoesNotExist($path);
    }

    public function test_overlapping_statement_adds_only_new_lines(): void
    {
        $this->bill();
        $proof = $this->pendingProof(105);
        $first = $this->import($this->pdf('first'));
        $this->assertSame('verified', $proof->fresh()->status);

        $this->text = $this->extendedText();
        $second = $this->import($this->pdf('second'));

        $this->assertSame(5, $second->lines_duplicate);
        $this->assertSame(1, $second->lines_new);
        $this->assertSame(6, BankStatementLine::count());
        $this->assertSame(0, $second->matchResult->autoVerified);
        $this->assertSame(0, BankStatementImport::withOutcomeCounts()->findOrFail($second->id)->auto_verified_count);
        $this->assertSame(1, BankStatementImport::withOutcomeCounts()->findOrFail($first->id)->auto_verified_count);
        $this->assertSame(1, PaymentProof::where('status', 'verified')->count());
        $this->assertSame(1, BankStatementLine::where('outcome', StatementLineOutcome::AutoVerified)->count());
    }

    public function test_unmatched_line_from_an_earlier_import_matches_later(): void
    {
        $this->bill();
        $first = $this->import($this->pdf('first'));
        $this->assertSame(0, $first->matchResult->autoVerified);
        $old = BankStatementLine::where('amount', '105.00')->firstOrFail();
        $this->assertSame(StatementLineOutcome::NoProof, $old->outcome);

        $proof = $this->pendingProof(105);
        $this->text = $this->extendedText();
        $second = $this->import($this->pdf('second'));

        $old->refresh();
        $this->assertSame(StatementLineOutcome::AutoVerified, $old->outcome);
        $this->assertSame($proof->id, $old->payment_proof_id);
        $this->assertSame('verified', $proof->fresh()->status);
        $this->assertSame(1, $second->matchResult->autoVerified);
    }

    public function test_two_identical_credits_on_one_day_are_both_stored(): void
    {
        $this->text = $this->twinCreditText();

        $import = $this->import($this->pdf());

        $this->assertSame(6, $import->lines_new);
        $this->assertSame(0, $import->lines_duplicate);
        $this->assertSame(6, BankStatementLine::count());
        $this->assertSame([1, 2], BankStatementLine::where('bank_ref_no', '800000005')->orderBy('occurrence')->pluck('occurrence')->all());
    }

    public function test_reimporting_a_statement_with_twin_credits_counts_both_as_duplicates(): void
    {
        $this->text = $this->twinCreditText();
        $this->import($this->pdf('first'));

        // The same period again plus one newer credit.
        $twin = '28/09/2024       DUITNOW TO ACCOUNT                        800000005                                     1,050.00         2,749.90';
        $newer = '29/09/2024       DUITNOW TO ACCOUNT                        800000006                                        20.00         2,769.90';
        $this->text = str_replace($twin, $newer."\n                 extra\n\n".$twin, $this->text);
        $this->text = preg_replace('/\b6(\s+500\.10\s+)2,250\.00/', '7${1}2,270.00', $this->text, -1, $count);
        $this->assertSame(1, $count);

        $second = $this->import($this->pdf('second'));

        $this->assertSame(6, $second->lines_duplicate);
        $this->assertSame(1, $second->lines_new);
        $this->assertSame(7, BankStatementLine::count());
    }

    public function test_an_earlier_no_code_line_is_not_matched_again(): void
    {
        $this->import($this->pdf('first'));
        $noCode = BankStatementLine::where('bank_ref_no', '900000002')->firstOrFail();
        $this->assertSame(StatementLineOutcome::NoCode, $noCode->outcome);
        $stamp = $noCode->updated_at->toDateTimeString();

        $this->travel(2)->hours();
        $this->text = $this->extendedText();
        $this->import($this->pdf('second'));

        $noCode->refresh();
        $this->assertSame(StatementLineOutcome::NoCode, $noCode->outcome);
        $this->assertSame($stamp, $noCode->updated_at->toDateTimeString());
    }

    public function test_import_counts_come_from_its_own_lines_after_an_earlier_line_resolves(): void
    {
        $this->bill();
        $first = $this->import($this->pdf('first'));
        $before = BankStatementImport::withOutcomeCounts()->findOrFail($first->id);
        $this->assertSame(0, $before->auto_verified_count);
        $this->assertSame(5, $before->to_review_count);

        $this->pendingProof(105);
        $this->text = $this->extendedText();
        $second = $this->import($this->pdf('second'));

        // The run that resolved the old line reports it ...
        $this->assertSame(1, $second->matchResult->autoVerified);
        // ... but each import's own counts follow its own lines.
        $first = BankStatementImport::withOutcomeCounts()->findOrFail($first->id);
        $this->assertSame(1, $first->auto_verified_count);
        $this->assertSame(4, $first->to_review_count);
        $second = BankStatementImport::withOutcomeCounts()->findOrFail($second->id);
        $this->assertSame(0, $second->auto_verified_count);
        $this->assertSame(1, $second->to_review_count);
    }

    public function test_unreconciled_statement_stores_nothing_and_deletes_the_file(): void
    {
        $this->text = str_replace('1,200.00', '1,300.00', $this->text);
        $path = $this->pdf();

        try {
            $this->import($path);
            $this->fail('Expected StatementParseException.');
        } catch (StatementParseException) {
        }

        $this->assertSame(0, BankStatementImport::count());
        $this->assertSame(0, BankStatementLine::count());
        $this->assertFileDoesNotExist($path);
    }

    public function test_matching_runs_after_the_import_transaction_has_committed(): void
    {
        $levels = [];
        $this->app->bind(StatementMatcher::class, function ($app) use (&$levels) {
            return new class($app->make(FinanceService::class), $app->make(MemberNotifier::class), $levels) extends StatementMatcher
            {
                public function __construct(FinanceService $finance, MemberNotifier $notifier, private array &$levels)
                {
                    parent::__construct($finance, $notifier);
                }

                public function match(Collection $lines): MatchResult
                {
                    $this->levels[] = DB::transactionLevel();

                    return parent::match($lines);
                }
            };
        });
        $baseline = DB::transactionLevel(); // RefreshDatabase keeps the whole test in one transaction.

        $this->import($this->pdf());

        $this->assertSame([$baseline], $levels);
    }

    private function bill(): void
    {
        foreach (['2024-07-01', '2024-08-01', '2024-09-01'] as $month) {
            FeePeriod::create([
                'user_id' => $this->member->id,
                'month_date' => $month,
                'amount_due' => 35,
                'status' => 'unpaid',
            ]);
        }
    }
}
