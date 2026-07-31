<?php

namespace App\Filament\Admin\Resources\SlaPolicies\Tables;

use App\Filament\Admin\Resources\SlaPolicies\SlaPolicyResource;
use App\Models\SlaPolicy;
use App\Support\SlaResolver;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SlaPoliciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // No search box: SlaPolicy carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
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
                    ->description(fn (SlaPolicy $record) => $record->is_active
                        ? __('admin.preventive_maintenance.sla.global_default').': '.SlaResolver::globalHoursFor($record->priority).'h'
                        : __('admin.preventive_maintenance.sla.inactive_note'))
                    ->color(fn (SlaPolicy $record) => $record->is_active ? null : 'gray')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('admin.preventive_maintenance.fields.active'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin.preventive_maintenance.fields.active')),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => SlaPolicyResource::canView($record))
                    ->authorize(fn ($record) => SlaPolicyResource::canView($record)),
                EditAction::make()->visible(fn (SlaPolicy $record) => SlaPolicyResource::canEdit($record)),
            ])
            ->defaultSort('asset_id')
            ->emptyStateIcon('heroicon-o-clock')
            ->emptyStateHeading(__('admin.empty.sla_policies.heading'))
            ->emptyStateDescription(__('admin.empty.sla_policies.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.sla_policies.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
