<?php

namespace App\Filament\Admin\Resources\ViolationCategories\Tables;

use App\Models\ViolationCategory;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ViolationCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->label(__('admin.fields.code'))->searchable()->sortable(),

                TextColumn::make('label')
                    ->label(__('admin.fields.name'))
                    ->state(fn (ViolationCategory $record): string => $record->label()),

                TextColumn::make('default_fine_amount')
                    ->label(__('admin.fields.default_fine_amount'))
                    ->money(config('app.currency', 'EGP'))
                    // Null and zero are different claims — most house rules are warned about before
                    // they are charged for, and a blank says the book names no tariff.
                    ->placeholder(__('admin.violation_categories_screen.no_fine')),

                TextColumn::make('violations_count')
                    ->label(__('admin.violation_categories_screen.recorded'))
                    // What makes a rule undeletable, shown so the refusal is not a surprise.
                    ->counts('violations')
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
            ->emptyStateIcon('heroicon-o-flag')
            ->emptyStateHeading(__('admin.empty.violation_categories.heading'))
            ->emptyStateDescription(__('admin.empty.violation_categories.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.violation_categories.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
