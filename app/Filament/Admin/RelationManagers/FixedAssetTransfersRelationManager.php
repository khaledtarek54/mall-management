<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Support\Modules;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a fixed asset has BEEN — the register's answer to "where did that chiller go" (module 23,
 * meeting 2026-09-02 point 18). One row per transfer act, newest first: the date, the property it
 * left, the property it went to, the cost and accumulated depreciation that moved with it, the
 * reason the operator gave, and who did it.
 *
 * Read-only by construction. A transfer is written by the *Transfer* act on the asset's own page
 * (the `transfer` act in `FixedAssetActions::all()` → `TransferFixedAssetService`), which posts the OUT and IN legs
 * into both properties' books; nothing here creates, edits or deletes a row, and the model refuses
 * deletion outright (`#[NeverDeletable]`) — the way back is a second transfer.
 */
class FixedAssetTransfersRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'transfers';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.fixed_assets.transfers');
    }

    /** Only when the fixed-asset module is on AND the user may view it — the schedule tab's own gate. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Modules::enabled('fixed_assets') && (auth()->user()?->can('fixed_assets.view') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: a transfer carries no `search_text` blob (nobody hunts for one by
            // name — they open the asset) and this table marks no column searchable. Without this,
            // TableDefaults' blob search would still render the box, and a search box that always
            // returns nothing reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with(['fromAsset', 'toAsset', 'createdBy']))
            ->columns([
                TextColumn::make('transferred_on')
                    ->label(__('admin.fields.transferred_on'))
                    ->date()
                    ->sortable(),
                TextColumn::make('fromAsset.name')
                    ->label(__('admin.fields.from_asset_id')),
                TextColumn::make('toAsset.name')
                    ->label(__('admin.fields.to_asset_id')),
                TextColumn::make('cost')
                    ->label(__('admin.fixed_assets.fields.acquisition_cost'))
                    ->money('EGP'),
                TextColumn::make('accumulated_depreciation')
                    ->label(__('admin.fields.accumulated_depreciation'))
                    ->money('EGP'),
                TextColumn::make('reason')
                    ->label(__('admin.fields.reason'))
                    ->wrap()
                    ->limit(80)
                    ->tooltip(fn ($record): ?string => $record->reason),
                TextColumn::make('createdBy.name')
                    ->label(__('admin.fixed_assets.fields.posted_by'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('transferred_on', 'desc');
    }
}
