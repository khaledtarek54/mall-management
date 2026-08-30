<?php

namespace App\Filament\Admin\Resources\RetailCategories\Tables;

use App\Models\RetailCategory;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RetailCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->label(__('admin.fields.code'))->searchable()->sortable(),

                TextColumn::make('label')
                    ->label(__('admin.fields.name'))
                    ->state(fn (RetailCategory $record): string => $record->label()),

                TextColumn::make('tenants_count')
                    ->label(__('admin.retail_categories_screen.retailers'))
                    // What makes a category undeletable, shown so the refusal is not a surprise.
                    ->counts('tenants')
                    ->badge(),

                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label(__('admin.fields.is_active'))])
            ->recordActions([
                // A read-only view, for the role that holds `.view` and not `.edit`. Its schema is the
                // resource's own form rendered disabled, so it cannot drift from the fields that exist.
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateIcon('heroicon-o-shopping-bag')
            ->emptyStateHeading(__('admin.empty.retail_categories.heading'))
            ->emptyStateDescription(__('admin.empty.retail_categories.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.retail_categories.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
