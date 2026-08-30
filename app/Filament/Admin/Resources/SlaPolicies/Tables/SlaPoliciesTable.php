<?php

namespace App\Filament\Admin\Resources\SlaPolicies\Tables;

use App\Enums\TenantRequestType;
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
                    ->label(__('admin.facility.fields.property'))
                    ->badge()->color('gray')
                    ->sortable(),
                TextColumn::make('request_type')

                    ->label(__('admin.facility.sla.request_type'))

                    // Shown because a property can now hold several rows per priority, one per request

                    // type, and without this column they read as duplicates.

                    ->formatStateUsing(fn (?string $state) => $state === null || $state === SlaPolicy::ANY_TYPE

                        ? __('admin.facility.sla.any_request_type')

                        : (TenantRequestType::tryFrom($state)?->label() ?? $state))

                    ->badge()
                    // Sortable because it is what the list opens on — a header the reader
                    // cannot click is a sort they cannot undo.
                    ->sortable(),
                TextColumn::make('priority')
                    ->label(__('admin.facility.fields.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.facility.priorities.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'low' => 'gray',
                        default => 'info',
                    })
                    ->sortable(),
                TextColumn::make('resolve_hours')
                    ->label(__('admin.facility.sla.hours'))
                    // Against the operator-wide default, so the point of the override reads
                    // at a glance instead of needing the settings page open alongside.
                    ->description(fn (SlaPolicy $record) => $record->is_active
                        ? __('admin.facility.sla.global_default').': '.SlaResolver::globalHoursFor($record->priority).'h'
                        : __('admin.facility.sla.inactive_note'))
                    ->color(fn (SlaPolicy $record) => $record->is_active ? null : 'gray')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('admin.facility.fields.active'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin.facility.fields.active')),
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
            // Grouped by the KIND of request the override governs.
            //
            // It sorted by `asset_id`, which ordered the rows by whichever sequence the malls
            // happened to be created in. Worse than arbitrary here: `SlaPolicy` is
            // `#[PropertyOwned]` with no portfolio tier, so the list only ever shows ONE mall's
            // rows — every value in that column is identical and the sort decided nothing at all,
            // leaving the rows in primary-key order behind a join that bought nothing.
            //
            // `request_type` is the column that tells these rows apart; it was added for exactly
            // that reason ("without this column they read as duplicates"). Priority would be the
            // other candidate and cannot be a column sort — its order is severity, not the
            // alphabet, and `FIELD()` is MySQL-only.
            ->defaultSort('request_type')
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
