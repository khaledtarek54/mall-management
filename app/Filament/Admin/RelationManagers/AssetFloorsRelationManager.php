<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\Floor;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The property's floors — set up here, selected everywhere else.
 *
 * **Why this lives on the property and not in the navigation.** A floor register is property SETUP:
 * eight rows typed once when the mall is created, then never touched. A top-level nav entry would
 * spend a permanent slot on a screen an operator visits twice a year, and it would separate the
 * floors from the property they describe — which is the click-budget rule (UX rule 1) working
 * against itself. Units and zones are already managed this way.
 */
class AssetFloorsRelationManager extends RelationManager
{
    protected static string $relationship = 'floors';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.floor.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label(__('admin.fields.floor_code'))
                ->required()
                ->maxLength(16)
                ->helperText(__('admin.helpers.floor_code'))
                // Unique per property, not globally — every mall has a ground floor.
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule) => $rule->where('asset_id', $this->getOwnerRecord()->getKey()),
                ),
            TextInput::make('name')
                ->label(__('admin.fields.floor_name'))
                ->maxLength(255)
                ->placeholder(__('admin.helpers.floor_name_placeholder')),
            TextInput::make('level')
                ->label(__('admin.fields.floor_level'))
                ->numeric()
                ->required()
                ->minValue(-20)
                ->maxValue(200)
                ->helperText(__('admin.helpers.floor_level'))
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule) => $rule->where('asset_id', $this->getOwnerRecord()->getKey()),
                ),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            // Bottom-up, the way a building is read.
            ->defaultSort('level')
            // No search box: a property has perhaps eight floors, all visible at once, and `Floor`
            // carries no `search_text` blob — a box here could never match anything.
            ->searchable(false)
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.fields.floor_code'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('admin.fields.floor_name'))
                    ->placeholder('—'),
                TextColumn::make('level')
                    ->label(__('admin.fields.floor_level'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('units_count')
                    ->label(__('admin.resources.unit.plural'))
                    ->counts('units')
                    ->badge(),
                TextColumn::make('rentable_items_count')
                    ->label(__('admin.resources.rentable_item.plural'))
                    ->counts('rentableItems')
                    ->badge()
                    ->color('info'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('assets.edit') ?? false)
                    ->authorize(fn (): bool => auth()->user()?->can('assets.edit') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('assets.edit') ?? false)
                    ->authorize(fn (): bool => auth()->user()?->can('assets.edit') ?? false),
                // A floor holding units or bays is refused at the model
                // (DeletionPolicy::WHEN_UNUSED) — the button is here for the empty ones, and the
                // refusal explains itself when it is not.
                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                    ->authorize(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false),
            ]);
    }
}
