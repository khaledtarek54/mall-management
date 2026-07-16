<?php

namespace App\Filament\Admin\Resources\MaintenancePlans\Tables;

use App\Filament\Admin\Resources\MaintenancePlans\MaintenancePlanResource;
use App\Models\MaintenancePlan;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MaintenancePlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'unit', 'equipment']))
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.preventive_maintenance.fields.title'))
                    ->weight('bold')
                    ->description(fn (MaintenancePlan $record) => __("admin.preventive_maintenance.categories.{$record->category}"))
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.preventive_maintenance.fields.property'))
                    ->badge()->color('gray')->toggleable(),
                TextColumn::make('maintenance_type')
                    ->label(__('admin.preventive_maintenance.fields.maintenance_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.preventive_maintenance.maintenance_types.{$state}"))
                    ->color(fn (string $state) => $state === MaintenancePlan::MAINTENANCE_TYPE_FIXED ? 'info' : 'gray')
                    ->toggleable(),
                TextColumn::make('equipment.code')
                    ->label(__('admin.preventive_maintenance.equipment.singular'))
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('unit.code')
                    ->label(__('admin.preventive_maintenance.fields.unit'))
                    ->placeholder('—'),
                TextColumn::make('frequency')
                    ->label(__('admin.preventive_maintenance.fields.frequency'))
                    ->state(fn (MaintenancePlan $record) => $record->frequency_value.' '.__("admin.preventive_maintenance.frequency_units.{$record->frequency_unit}")),
                TextColumn::make('next_due_date')
                    ->label(__('admin.preventive_maintenance.fields.next_due'))
                    ->date('d/m/Y')
                    ->sortable()
                    // Highlight overdue plans.
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : 'success'),
                IconColumn::make('is_active')
                    ->label(__('admin.preventive_maintenance.fields.active'))
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (MaintenancePlan $record) => MaintenancePlanResource::canEdit($record)),
            ])
            ->defaultSort('next_due_date');
    }
}
