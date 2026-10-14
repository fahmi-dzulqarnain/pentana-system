<?php

namespace App\Filament\Resources\FeePayments\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Support\Facades\Auth;

class FeePaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Submit Fee Payment'))
                    ->description(__('Please enter the total amount transferred and upload your bank receipt.'))
                    ->schema([
                        // Automatically associate with the logged-in member
                        Hidden::make('user_id')
                            ->default(fn () => Auth::id()),

                        TextInput::make('amount_claimed')
                            ->label(__('Total Amount Transferred'))
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
                            ->numeric()
                            ->prefix('MYR')
                            ->placeholder(__('e.g. 100.00'))
                            ->default(fn () => Auth::user()->active_fee)
                            ->helperText(fn () => __('Your category rate is MYR :amount', ['amount' => number_format(Auth::user()->active_fee, 2)]))
                            ->required(),

                        FileUpload::make('proof_image_path')
                            ->label(__('Upload Receipt'))
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('receipts')
                            ->visibility('public')
                            ->required(),

                        Textarea::make('member_note')
                            ->label(__('Notes (Optional)'))
                            ->placeholder(__('e.g. Paying for Jan and Feb 2026'))
                            ->rows(3),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
