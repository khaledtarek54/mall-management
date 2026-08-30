<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\Warehouses\WarehouseResource;
use App\Models\Bin;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The shelves inside this storeroom.
 *
 * A warehouse says which mall's storeroom; a bin says where in it. Without one, a storeroom holding
 * four hundred parts is a single undifferentiated box — "we have six of those" is true and useless,
 * because nobody can find them.
 *
 * Lives on the warehouse rather than as a top-level resource, because a bin has no meaning apart
 * from its storeroom: `A-01` is an ordinary aisle in every mall in the portfolio, and a portfolio
 * list of them would be a list of duplicate labels. Property isolation comes free for the same
 * reason — the parent is already scoped, and `Bin` is `#[PropertyOwned(via: 'warehouse')]`.
 */
class WarehouseBinsRelationManager extends RelationManager
{
    protected static string $relationship = 'bins';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.inventory.bins.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')
                ->label(__('admin.inventory.bins.code'))
                ->required()
                ->maxLength(32)
                // Unique WITHIN this warehouse: "A-01" is a normal aisle label in every storeroom,
                // so a global unique would stop the second mall using its own signage.
                ->unique(
                    table: 'bins',
                    column: 'code',
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule) => $rule->where('warehouse_id', $this->getOwnerRecord()->getKey()),
                )
                ->helperText(__('admin.inventory.bins.code_helper')),
            TextInput::make('name')
                ->label(__('admin.inventory.bins.name'))
                ->maxLength(255),
            Toggle::make('is_active')
                ->label(__('admin.fields.is_active'))
                ->default(true)
                ->helperText(__('admin.inventory.bins.is_active_helper')),
            Textarea::make('notes')
                ->label(__('admin.fields.notes'))
                ->maxLength(500)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: a storeroom's bins are a short list the reader can already see, and
            // `Bin` carries no `search_text` blob for one to match against.
            ->searchable(false)
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.inventory.bins.code'))
                    ->fontFamily('mono')
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('admin.inventory.bins.name'))
                    ->placeholder('—'),
                // What is actually on the shelf — derived from the movements, never stored.
                TextColumn::make('items_on_hand')
                    ->label(__('admin.inventory.bins.items_on_hand'))
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                    ->getStateUsing(fn (Bin $record) => $record->onHandByItem()->count()),
                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.actions.add_bin'))
                    ->modalHeading(__('admin.actions.add_bin'))
                    ->visible(fn () => WarehouseResource::canCreate())
                    ->authorize(fn () => WarehouseResource::canCreate()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => WarehouseResource::canEdit($this->getOwnerRecord()))
                    ->authorize(fn () => WarehouseResource::canEdit($this->getOwnerRecord())),
                // Refused by the model once anything has moved through the bin
                // (RefusesDeletionWhenReferenced) — deactivating is the way to retire one.
                DeleteAction::make()
                    ->visible(fn () => WarehouseResource::canDelete($this->getOwnerRecord()))
                    ->authorize(fn () => WarehouseResource::canDelete($this->getOwnerRecord())),
            ])
            ->defaultSort('code')
            ->emptyStateIcon('heroicon-o-squares-2x2')
            ->emptyStateHeading(__('admin.inventory.bins.empty_heading'))
            ->emptyStateDescription(__('admin.inventory.bins.empty_description'));
    }
}
