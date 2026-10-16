<?php

namespace App\Filament\Resources\Caterers\RelationManagers;

use App\Filament\Support\GuardsLunchDayDeletes;
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
use Illuminate\Database\Eloquent\Model;

class CatererMealsRelationManager extends RelationManager
{
    protected static string $relationship = 'meals';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Meals');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('price')
                    ->label(__('Price'))
                    ->numeric()
                    ->prefix('MYR')
                    ->required(),
                FileUpload::make('image')
                    ->label(__('Image'))
                    ->image()
                    ->disk('public')
                    ->directory('caterer-meals')
                    ->visibility('public'),
                Repeater::make('options')
                    ->label(__('Options'))
                    ->relationship('options')
                    ->schema([
                        Select::make('meal_option_id')
                            ->label(__('Option'))
                            ->options(MealOption::query()->where('is_active', true)->orderBy('sort')->pluck('name', 'id'))
                            ->required(),
                        TextInput::make('price')
                            ->label(__('Price'))
                            ->numeric()
                            ->prefix('MYR')
                            ->helperText(__('Leave blank to use the item price.')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('caterer meal'))
            ->pluralModelLabel(__('caterer meals'))
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable(),
                TextColumn::make('price')
                    ->label(__('Price'))
                    ->money('MYR')
                    ->sortable(),
                TextColumn::make('options_count')
                    ->counts('options')
                    ->label(__('Options')),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
                Action::make('copyFromCatalog')
                    ->label(__('Copy from catalog'))
                    ->icon('heroicon-o-document-duplicate')
                    ->schema([
                        Select::make('meals')
                            ->label(__('Meals'))
                            ->multiple()
                            ->options(Meal::query()->pluck('name', 'id'))
                            ->required(),
                    ])
                    ->action(function (array $data, LunchCatalogService $service, self $livewire) {
                        $caterer = $livewire->getOwnerRecord();
                        foreach (Meal::with('options')->findMany($data['meals']) as $meal) {
                            $service->copyMealToCaterer($meal, $caterer);
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                GuardsLunchDayDeletes::single(DeleteAction::make(), fn (int $count): string => __('This menu is used by :count lunch days, so it can\'t be deleted.', ['count' => $count])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GuardsLunchDayDeletes::bulk(DeleteBulkAction::make()),
                ]),
            ]);
    }
}
