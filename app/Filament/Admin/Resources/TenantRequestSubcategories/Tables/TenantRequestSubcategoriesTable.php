<?php

namespace App\Filament\Admin\Resources\TenantRequestSubcategories\Tables;

use App\Enums\TenantRequestType;
use App\Models\TenantRequestSubcategory;
use App\Support\Filament\TableGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TenantRequestSubcategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup(TableGroup::byColumn($table, 'request_type'))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('request_type')
                    ->label(__('admin.fields.request_type'))
                    ->formatStateUsing(fn (?string $state) => TenantRequestType::tryFrom((string) $state)?->label() ?? $state)
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('code')->label(__('admin.fields.code'))->searchable()->sortable(),

                TextColumn::make('label')
                    ->label(__('admin.fields.name'))
                    ->state(fn (TenantRequestSubcategory $record): string => $record->label()),

                TextColumn::make('trade.name')
                    ->label(__('admin.fields.trade'))
                    // The em-dash is the honest answer, not a gap: most subcategories are not trades.
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (TenantRequestSubcategory $record): string => $record->trade_id !== null ? 'success' : 'gray'),

                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('admin.fields.is_active')),
                SelectFilter::make('request_type')
                    ->label(__('admin.fields.request_type'))
                    ->options(fn () => collect(TenantRequestType::cases())
                        ->mapWithKeys(fn (TenantRequestType $t) => [$t->value => $t->label()])->all()),
            ])
            ->recordActions([
                // A read-only view, for the role that holds `.view` and not `.edit`. Its schema is the
                // resource's own form rendered disabled, so it cannot drift from the fields that exist.
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateIcon('heroicon-o-queue-list')
            ->emptyStateHeading(__('admin.empty.tenant_request_subcategories.heading'))
            ->emptyStateDescription(__('admin.empty.tenant_request_subcategories.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.tenant_request_subcategories.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
