<?php

namespace App\Filament\Admin\Resources\SlaPolicies\Tables;

use App\Filament\Admin\Resources\SlaPolicies\SlaPolicyResource;
use App\Models\SlaPolicy;
use App\Support\SlaResolver;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SlaPoliciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('asset'))
            ->columns([
                TextColumn::make('asset.name')
                    ->label(__('admin.preventive_maintenance.fields.property'))
                    ->badge()->color('gray')
                    ->sortable(),
                TextColumn::make('priority')
                    ->label(__('admin.preventive_maintenance.fields.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.preventive_maintenance.priorities.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'low' => 'gray',
                        default => 'info',
                    })
                    ->sortable(),
                TextColumn::make('resolve_hours')
                    ->label(__('admin.preventive_maintenance.sla.hours'))
                    // Against the operator-wide default, so the point of the override reads
                    // at a glance instead of needing the settings page open alongside.
                    ->description(fn (SlaPolicy $record) => __('admin.preventive_maintenance.sla.global_default')
                        .': '.SlaResolver::globalHoursFor($record->priority).'h')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (SlaPolicy $record) => SlaPolicyResource::canEdit($record)),
            ])
            ->defaultSort('asset_id');
    }
}
