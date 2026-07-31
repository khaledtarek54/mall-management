<?php

namespace App\Filament\Admin\RelationManagers;

use App\Support\Modules;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only depreciation schedule for a fixed asset (module 23). Rows are created
 * only by the monthly run (DepreciationService / accounting:post-depreciation), never
 * by hand — so there are no create/edit/delete actions here.
 */
class DepreciationEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'depreciationEntries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.fixed_assets.history');
    }

    /** Only when the fixed-asset module is on AND the user may view it. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Modules::enabled('fixed_assets') && (auth()->user()?->can('fixed_assets.view') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: DepreciationEntry carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with('createdBy'))
            ->columns([
                TextColumn::make('period_month')
                    ->label(__('admin.fixed_assets.fields.period'))
                    ->date('M Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label(__('admin.fixed_assets.fields.amount'))
                    ->money('EGP'),
                TextColumn::make('createdBy.name')
                    ->label(__('admin.fixed_assets.fields.posted_by'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('period_month', 'desc');
    }
}
