<?php

namespace App\Filament\Admin\Resources\FailureCodes\Tables;

use App\Models\FailureCode;
use App\Support\Filament\TableGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FailureCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // A library of operator configuration, maintained by scrolling and filtering by type.
            // `TableDefaults` would otherwise render the folded-blob search box, and `FailureCode`
            // carries no blob — a box that always returns nothing reads as "no such record".
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with('trade'))
            ->columns([
                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.facility.failure_types.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        FailureCode::TYPE_PROBLEM => 'danger',
                        FailureCode::TYPE_CAUSE => 'warning',
                        default => 'success',
                    })
                    ->sortable(),

                TextColumn::make('code')->label(__('admin.fields.code'))->fontFamily('mono')->size('xs'),

                TextColumn::make('name')
                    ->label(__('admin.fields.name'))
                    ->state(fn (FailureCode $r): string => $r->label())
                    ->weight('bold'),

                TextColumn::make('trade')
                    ->label(__('admin.facility.fields.trade'))
                    ->badge()
                    // Blank means "every trade", which is a fact worth stating rather than a dash
                    // an operator has to interpret.
                    ->state(fn (FailureCode $r): string => $r->trade?->label() ?? __('admin.facility.failure_any_trade')),

                IconColumn::make('is_active')->label(__('admin.fields.active'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.fields.type'))
                    ->options(fn () => collect(FailureCode::TYPES)
                        ->mapWithKeys(fn (string $t) => [$t => __("admin.facility.failure_types.{$t}")])
                        ->all()),
                TernaryFilter::make('is_active')->label(__('admin.fields.active')),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->defaultSort('type')
            ->defaultGroup(TableGroup::byColumn($table, 'type'))
            ->emptyStateIcon('heroicon-o-exclamation-triangle')
            ->emptyStateHeading(__('admin.empty.failure_codes.heading'))
            ->emptyStateDescription(__('admin.empty.failure_codes.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.failure_codes.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
