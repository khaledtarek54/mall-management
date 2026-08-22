<?php

namespace App\Filament\Admin\Resources\Units\Tables;

use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Exports\UnitExporter;
use App\Models\Asset;
use App\Models\Unit;
use App\Services\RemeasureUnitService;
use App\Support\Exports;
use App\Support\Filament\EntitySelectFilter;
use App\Support\Filament\PropertyField;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'area', 'activeLease.tenant']))
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.tables.unit.code'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('floor.code')
                    ->label(__('admin.pdf.floor'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('category')
                    ->label(__('admin.tables.unit.category'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.category.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'food_beverage' => 'warning',
                        'retail' => 'info',
                        'wellness' => 'success',
                        'service' => 'gray',
                        'kiosk' => 'purple',
                        default => 'gray',
                    }),
                TextColumn::make('area_sqm')
                    ->label(__('admin.tables.unit.area'))
                    ->numeric(decimalPlaces: 0)
                    ->sortable()
                    ->alignRight(),
                TextColumn::make('area.name')
                    ->label(__('admin.tables.unit.area_zone'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('activeLease.tenant.name')
                    ->label(__('admin.tables.unit.tenant'))
                    ->searchable()
                    ->placeholder('—')
                    ->weight('medium'),
                TextColumn::make('activeLease.base_rent_monthly')
                    ->label(__('admin.tables.unit.rent'))
                    ->money('EGP')
                    ->placeholder('—')
                    ->sortable()
                    ->alignRight(),
                TextColumn::make('activeLease.expiry_date')
                    ->label(__('admin.widgets.top_tenants.lease_ends'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->color(function ($record) {
                        if (! $record->activeLease) {
                            return 'gray';
                        }
                        $days = (int) now()->diffInDays($record->activeLease->expiry_date, false);
                        if ($days < 30) {
                            return 'danger';
                        }
                        if ($days < 90) {
                            return 'warning';
                        }

                        return 'success';
                    }),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.unit.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'occupied' => 'success',
                        'vacant' => 'danger',
                        'reserved' => 'warning',
                        'maintenance' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.unit')),
                SelectFilter::make('category')
                    ->label(__('admin.tables.unit.category'))
                    ->options(fn () => collect(__('admin.enums.category'))->except(['office', 'storage'])->all()),
                EntitySelectFilter::make('asset_id')
                    ->label(__('admin.filters.asset'))
                    // Scope to the user's visible properties (excludes the ALL pseudo-asset) — a raw
                    // ->relationship('asset','name') enumerates every mall's name + the ALL row to a
                    // restricted operator (a cross-property metadata read leak).
                    //
                    // Hidden outright while a mall is selected, which on an operational screen is
                    // always: the table is already scoped to that mall, so the filter offered a list
                    // of one and narrowed nothing — an operator opening the filter panel to narrow
                    // by property found the question already answered and no way to widen it. It
                    // stays declared for the All-Properties plumbing, the one place two malls need
                    // telling apart.
                    ->entity(Asset::class)
                    ->searchable()
                    ->visible(fn (): bool => ! PropertyField::isPinned()),
                Filter::make('lease_expiring_soon')
                    ->label(__('admin.filters.expiring_soon'))
                    ->query(fn (Builder $query) => $query->whereHas('activeLease', fn (Builder $l) => $l->whereBetween('expiry_date', [now(), now()->addDays(90)]))),
                Filter::make('lease_expiring_critical')
                    ->label(__('admin.filters.expiring_critical'))
                    ->query(fn (Builder $query) => $query->whereHas('activeLease', fn (Builder $l) => $l->whereBetween('expiry_date', [now(), now()->addDays(30)]))),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->headerActions([
                ExportAction::make()
                    ->exporter(UnitExporter::class)
                    ->label(__('admin.actions.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => Exports::allowed(UnitResource::class))
                    ->authorize(fn (): bool => Exports::allowed(UnitResource::class)),
            ])
            // Floor is how a leasing manager physically walks the mall; status is the vacancy view.
            ->groups([
                Group::make('floor.name')->label(__('admin.tables.unit.floor'))->collapsible(),
                Group::make('status')->label(__('admin.filters.status'))->collapsible(),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => UnitResource::canView($record))
                    ->authorize(fn ($record) => UnitResource::canView($record)),
                EditAction::make()
                    ->visible(fn ($record) => UnitResource::canEdit($record)),
                // Change a unit's measured area — the ONLY path that may, because it is the only
                // one that dates the change. `RemeasureUnitService` shipped with the versioning
                // feature and had no caller anywhere in app/: the register existed, nothing could
                // add to it, and the only reachable way to change an area was the Edit form's
                // plain `area_sqm` field, which bypassed versioning entirely (validation sweep,
                // 2026-08-11). Closing that bypass without this action would have left operators
                // unable to record a re-survey at all.
                Action::make('remeasure')
                    ->label(__('admin.actions.remeasure_unit'))
                    ->icon('heroicon-o-variable')
                    ->color('gray')
                    ->visible(fn ($record) => UnitResource::canEdit($record))
                    ->authorize(fn ($record) => UnitResource::canEdit($record))
                    ->modalHeading(fn (Unit $record) => __('admin.actions.remeasure_unit_heading', ['unit' => $record->code]))
                    // The current figure goes in the description, so the operator is told what they
                    // are changing FROM before they type what it is changing to.
                    ->modalDescription(fn (Unit $record) => __('admin.actions.remeasure_unit_description', [
                        'current' => number_format((float) $record->area_sqm, 2),
                    ]))
                    ->schema([
                        TextInput::make('area_sqm')
                            ->label(__('admin.actions.remeasure_new_area'))
                            ->numeric()
                            ->minValue(0.01)
                            ->suffix('m²')
                            ->required(),
                        DatePicker::make('effective_from')
                            ->label(__('admin.actions.remeasure_effective_from'))
                            ->default(now())
                            ->native(false)
                            ->required()
                            ->helperText(__('admin.helpers.remeasure_effective_from'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.remeasure_effective_from')),
                        Textarea::make('reason')
                            ->label(__('admin.fields.reason'))
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (Unit $record, array $data): void {
                        // action() is the real gate; visible() is the UI.
                        abort_unless(UnitResource::canEdit($record), 403);

                        try {
                            app(RemeasureUnitService::class)->record($record, (float) $data['area_sqm'], [
                                'effective_from' => $data['effective_from'] ?? null,
                                'reason' => $data['reason'] ?? null,
                            ]);
                        } catch (\DomainException $e) {
                            // e.g. a date at or before the row it would close — a toast, not a 500.
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('admin.actions.remeasure_unit_done', [
                                'unit' => $record->code,
                                'area' => number_format((float) $record->fresh()->area_sqm, 2),
                            ]))
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(UnitExporter::class)
                        ->label(__('admin.actions.export'))
                        ->visible(fn (): bool => Exports::allowed(UnitResource::class))
                        ->authorize(fn (): bool => Exports::allowed(UnitResource::class)),
                    DeleteBulkAction::make()
                        ->visible(fn () => UnitResource::canDeleteAny()),
                    ForceDeleteBulkAction::make()
                        ->visible(fn () => UnitResource::canForceDeleteAny()),
                    RestoreBulkAction::make()
                        ->visible(fn () => UnitResource::canRestoreAny()),
                ]),
            ])
            ->defaultSort('code')
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateHeading(__('admin.empty.units.heading'))
            ->emptyStateDescription(__('admin.empty.units.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.units.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
