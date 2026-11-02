<?php

namespace App\Filament\Resources\FeePayments\Pages;

use App\Filament\Resources\FeePayments\Actions\PaymentReviewActions;
use App\Filament\Resources\FeePayments\FeePaymentResource;
use App\Filament\Resources\FeePayments\Tables\FeePaymentsTable;
use App\Filament\Resources\PaymentReceipts\Actions\DownloadReceiptAction;
use App\Filament\Support\MemberName;
use App\Models\Payment\BankStatementLine;
use App\Models\Payment\PaymentProof;
use App\Support\StatusLabel;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class ViewFeePayment extends ViewRecord
{
    protected static string $resource = FeePaymentResource::class;

    public function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->schema([
                Section::make(__('Payment details'))
                    ->schema([
                        MemberName::entry('user')->label(__('Member name')),
                        TextEntry::make('amount_claimed')->money('MYR'),
                        TextEntry::make('created_at')
                            ->label(__('Submitted'))
                            ->dateTime(),
                        TextEntry::make('member_note')
                            ->label(__('Member note'))
                            ->visible(fn ($record) => filled($record->member_note))
                            ->columnSpanFull(),
                        ImageEntry::make('proof_image_path')
                            ->label(__('Payment proof'))
                            ->disk('public')
                            ->visibility('public')
                            ->extraImgAttributes([
                                'alt' => __('Payment proof'),
                                // Inline styles: Filament's precompiled CSS doesn't ship arbitrary Tailwind utilities,
                                // so class-based styling here would silently do nothing.
                                // contain, not cover: the admin must see the whole receipt (amount, reference) to verify it.
                                'style' => 'width:100%;height:28rem;object-fit:contain;background:rgba(127,127,127,.08);border-radius:0.75rem;cursor:zoom-in;',
                            ])
                            ->action(
                                Action::make('viewReceipt')
                                    ->label(__('View proof'))
                                    ->modalHeading(__('Payment proof'))
                                    ->modalContent(fn ($record) => view('filament.infolists.receipt-modal', [
                                        'url' => Storage::disk('public')->url($record->proof_image_path),
                                    ]))
                                    ->modalSubmitAction(false)
                                    ->modalCancelActionLabel(__('Close')),
                            )
                            ->columnSpanFull(),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => FeePaymentsTable::statusColor($state))
                            ->formatStateUsing(fn (string $state): string => StatusLabel::for($state)),
                        TextEntry::make('admin_note')
                            ->label(__('Reason for rejection'))
                            ->visible(fn ($record) => $record->status === 'rejected' && filled($record->admin_note)),
                    ])
                    ->columns(2),

                Section::make(__('Possible bank match'))
                    ->description(__('A bank statement line carries this member\'s code but was not matched automatically. Check it, then verify as usual.'))
                    ->schema(fn (PaymentProof $record): array => BankStatementLine::possibleMatchesFor($record)
                        ->map(fn (BankStatementLine $line): Text => Text::make(sprintf(
                            '%s · MYR %s · %s %s · %s',
                            $line->txn_date->format('d/m/Y'),
                            number_format((float) $line->amount, 2),
                            __('CIMB ref'),
                            $line->bank_ref_no,
                            $line->reference_text,
                        )))
                        ->all())
                    ->visible(fn (PaymentProof $record): bool => $record->status === 'pending'
                        && (bool) auth()->user()?->canManageFinance()
                        && BankStatementLine::possibleMatchesFor($record)->isNotEmpty()),

                Section::make(__('Allocations'))
                    ->description(__('This shows which months this payment covered.'))
                    ->schema([
                        RepeatableEntry::make('allocations')
                            ->schema([
                                TextEntry::make('feePeriod.month_date')
                                    ->label(__('Month'))
                                    ->date('F Y'),
                                TextEntry::make('amount_allocated')
                                    ->money('MYR'),
                            ])
                            ->columns(2),
                    ])
                    ->visible(fn ($record) => $record->status === 'verified'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            DownloadReceiptAction::make(fn (PaymentProof $record) => $record->receipt),
            PaymentReviewActions::verify(),
            PaymentReviewActions::reject(),
            EditAction::make(),
        ];
    }
}
