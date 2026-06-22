<?php

namespace App\Filament\Resources\Caterers\RelationManagers;

use App\Models\Meal;
use App\Models\MealOption;
use App\Services\LunchCatalogService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CatererMealsRelationManager extends RelationManager
{
    protected static string $relationship = 'meals';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('price')
                    ->numeric()
                    ->prefix('MYR')
                    ->required(),
                FileUpload::make('image')
                    ->image()
                    ->disk('public')
                    ->directory('caterer-meals')
                    ->visibility('public'),
                Repeater::make('options')
                    ->relationship('options')
                    ->schema([
                        Select::make('meal_option_id')
                            ->label('Option')
                            ->options(MealOption::query()->where('is_active', true)->pluck('name', 'id'))
                            ->required(),
                        TextInput::make('price')
                            ->numeric()
                            ->prefix('MYR')
                            ->helperText('Leave blank to use the item price.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('price')
                    ->money('MYR')
                    ->sortable(),
                TextColumn::make('options_count')
                    ->counts('options')
                    ->label('Options'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
                Action::make('copyFromCatalog')
                    ->label('Copy from catalog')
                    ->icon('heroicon-o-document-duplicate')
                    ->schema([
                        Select::make('meals')
                            ->multiple()
                            ->options(Meal::query()->pluck('name', 'id'))
                            ->required(),
                    ])
                    ->action(function (array $data, LunchCatalogService $service) {
                        $caterer = $this->getOwnerRecord();
                        foreach (Meal::with('options')->findMany($data['meals']) as $meal) {
                            $service->copyMealToCaterer($meal, $caterer);
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
