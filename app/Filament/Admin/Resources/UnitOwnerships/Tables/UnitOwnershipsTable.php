<?php

namespace App\Filament\Admin\Resources\UnitOwnerships\Tables;

use App\Enums\UnitManagementMode;
use App\Enums\UnitOwnershipStatus;
use App\Enums\UnitTenureType;
use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ownership register — who owns which unit, and on what footing.
 *
 * Sorted by unit rather than by date: an operator arriving here is answering "who owns A-102",
 * not "what was sold most recently".
 */
class UnitOwnershipsTable
{
    public static function configure(Table $table): Table
    {
        // No TableDefaults call: `TableDefaults::register()` is a global
        // `Table::configureUsing()` applied to every table in the panel, so search persistence,
        // striping and pagination arrive here already.
        return $table
            ->defaultSort('unit.code')
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.fields.reference'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('unit.code')
                    ->label(__('admin.fields.unit_id'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('owner.name')
                    ->label(__('admin.unit_ownerships.fields.owner'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('tenure_type')
                    ->label(__('admin.fields.tenure_type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (UnitTenureType $state): string => $state->label()),

                TextColumn::make('management_mode')
                    ->label(__('admin.fields.management_mode'))
                    ->badge()
                    ->formatStateUsing(fn (UnitManagementMode $state): string => $state->label())
                    ->color(fn (UnitManagementMode $state): string => match ($state) {
                        UnitManagementMode::OperatorManaged => 'success',
                        UnitManagementMode::Vacant => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (UnitOwnershipStatus $state): string => $state->label())
                    ->color(fn (UnitOwnershipStatus $state): string => match ($state) {
                        UnitOwnershipStatus::HandedOver => 'success',
                        UnitOwnershipStatus::Transferred => 'gray',
                        default => 'info',
                    }),

                TextColumn::make('ownership_share_pct')
                    ->label(__('admin.fields.ownership_share_pct'))
                    ->suffix('%')
                    ->alignRight()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('started_at')
                    ->label(__('admin.fields.started_at'))
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('ended_at')
                    ->label(__('admin.fields.ended_at'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('management_mode')
                    ->label(__('admin.fields.management_mode'))
                    ->options(UnitManagementMode::options()),

                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(UnitOwnershipStatus::options()),

                SelectFilter::make('tenure_type')
                    ->label(__('admin.fields.tenure_type'))
                    ->options(UnitTenureType::options()),

                // The register accumulates former owners by design — a resale ends a tenure rather
                // than deleting it — so "who owns this today" needs to be one click, not a mental
                // filter over every row that ever existed.
                Filter::make('current')
                    ->label(__('admin.unit_ownerships.filters.current'))
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->covering()),

                TrashedFilter::make(),
            ])
            ->recordActions([
                // Read the sale without opening its edit form — the register is consulted far more
                // often than it is changed ("who owns A-102, and on what footing"), and a view-only
                // role must not be handed a write surface to answer that. The schema is the
                // resource's own form rendered disabled, so it cannot drift from the real fields.
                ViewAction::make()
                    ->visible(fn ($record): bool => UnitOwnershipResource::canView($record)),
                EditAction::make(),
            ]);
    }
}
