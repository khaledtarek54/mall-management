<?php

namespace App\Filament\Admin\Resources\RetailCategories\Tables;

use App\Models\RetailCategory;
use Filament\Actions\EditAction;
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
            ->recordActions([EditAction::make()]);
    }
}
