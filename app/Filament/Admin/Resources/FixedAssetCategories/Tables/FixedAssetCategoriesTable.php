<?php

namespace App\Filament\Admin\Resources\FixedAssetCategories\Tables;

use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Support\Modules;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FixedAssetCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->label(__('admin.fields.code'))->searchable()->sortable(),

                TextColumn::make('label')
                    ->label(__('admin.fields.name'))
                    ->state(fn (FixedAssetCategory $record): string => $record->label()),

                TextColumn::make('tag_prefix')
                    ->label(__('admin.fields.tag_prefix'))
                    ->fontFamily('mono')
                    ->formatStateUsing(fn (string $state): string => $state.'-0001…'),

                TextColumn::make('default_useful_life_months')
                    ->label(__('admin.fields.default_useful_life_months'))
                    // Months, and the rate they read as — the pair the asset form shows.
                    ->formatStateUsing(fn (?int $state): string => $state
                        ? __('admin.fixed_asset_categories_screen.life_and_rate', ['months' => $state, 'rate' => FixedAsset::annualRateFor($state)])
                        : '—'),

                TextColumn::make('default_salvage_value')
                    ->label(__('admin.fields.default_salvage_value'))
                    ->money(config('app.currency', 'EGP'))
                    ->placeholder('—'),

                TextColumn::make('default_tax_pool')
                    ->label(__('admin.fields.default_tax_pool'))
                    ->formatStateUsing(fn (?string $state): string => $state ? __("admin.tax_depreciation.pools.{$state}") : '—')
                    // With the tax schedule switched off the pool is a fact nothing reads.
                    ->visible(fn (): bool => Modules::enabled('tax_depreciation'))
                    ->toggleable(),

                TextColumn::make('fixed_assets_count')
                    ->label(__('admin.fixed_asset_categories_screen.registered'))
                    // What makes a class undeletable, shown so the refusal is not a surprise.
                    ->counts('fixedAssets')
                    ->badge(),

                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label(__('admin.fields.is_active'))])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateIcon('heroicon-o-rectangle-group')
            ->emptyStateHeading(__('admin.empty.fixed_asset_categories.heading'))
            ->emptyStateDescription(__('admin.empty.fixed_asset_categories.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.fixed_asset_categories.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
