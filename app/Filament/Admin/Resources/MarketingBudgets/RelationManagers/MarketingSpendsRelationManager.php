<?php

namespace App\Filament\Admin\Resources\MarketingBudgets\RelationManagers;

use App\Models\MarketingSpend;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Records marketing spend (offers / promotions / events / printed work) against
 * a budget. Each create/edit/delete keeps the budget's spent_amount derived via
 * the MarketingSpend model events (FR MKT-1/4/5).
 */
class MarketingSpendsRelationManager extends RelationManager
{
    protected static string $relationship = 'spends';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.marketing_spend.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('category')
                ->label(__('admin.tables.marketing_spend.category'))
                ->options(fn () => collect(MarketingSpend::CATEGORIES)->mapWithKeys(fn ($c) => [$c => Str::headline($c)]))
                ->default('other')
                ->required()
                ->native(false),
            TextInput::make('amount')
                ->label(__('admin.tables.marketing_spend.amount'))
                ->numeric()
                ->minValue(0)
                ->required(),
            DatePicker::make('spent_on')
                ->label(__('admin.tables.marketing_spend.spent_on'))
                ->default(now())
                ->native(false)
                ->required(),
            TextInput::make('receipt_reference')
                ->label(__('admin.tables.marketing_spend.receipt'))
                ->maxLength(255),
            TextInput::make('description')
                ->label(__('admin.tables.marketing_spend.description'))
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('spent_on')
                    ->label(__('admin.tables.marketing_spend.spent_on'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('category')
                    ->label(__('admin.tables.marketing_spend.category'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Str::headline($state))
                    ->color('gray'),
                TextColumn::make('description')
                    ->label(__('admin.tables.marketing_spend.description'))
                    ->limit(40),
                TextColumn::make('amount')
                    ->label(__('admin.tables.marketing_spend.amount'))
                    ->money('EGP')
                    ->weight('bold'),
                TextColumn::make('receipt_reference')
                    ->label(__('admin.tables.marketing_spend.receipt'))
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('spent_on', 'desc');
    }
}
