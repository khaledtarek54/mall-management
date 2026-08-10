<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\Lease;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Who has occupied this unit, and when.
 *
 * **The Unit resource had no relation managers at all** — not leases, not meters, not requests. So
 * the one question an operator asks standing in front of a shop ("who is in here, and who was here
 * before?") had no answer on the unit's own page; you had to go to Leases and filter. The data was
 * always there.
 *
 * Read-only on purpose. A lease is created from the leasing workflow, where the unit is one field
 * among the commercial terms — offering "add a lease" from a unit would invite a lease with no
 * negotiated rent. This lists and links; the Open action takes you to the real screen.
 *
 * Reads the `lease_unit` PIVOT (`allLeases`), not `leases`: a multi-unit lease holds this unit as an
 * ADDITIONAL unit without pointing `leases.unit_id` at it, and that lease occupies the shop just as
 * much as a single-unit one does.
 */
class UnitLeasesRelationManager extends RelationManager
{
    protected static string $relationship = 'allLeases';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('admin.unit_leases.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.lease.reference'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->searchable(),

                TextColumn::make('tenant.name')
                    ->label(__('admin.tables.lease.tenant'))
                    ->searchable()
                    // The sign above the door is what an operator recognises, not the legal entity.
                    ->description(fn (Lease $record) => $record->tenant?->trade_name),

                TextColumn::make('status')
                    ->label(__('admin.filters.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.lease.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'pending_approval', 'draft' => 'warning',
                        'terminated', 'cancelled' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('commencement_date')
                    ->label(__('admin.fields.commencement_date'))
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('expiry_date')
                    ->label(__('admin.fields.expiry_date'))
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('base_rent_monthly')
                    ->label(__('admin.fields.base_rent_monthly'))
                    ->money('EGP')
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Lease $record): string => \App\Filament\Admin\Resources\Leases\LeaseResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (Lease $record): bool => \App\Filament\Admin\Resources\Leases\LeaseResource::canEdit($record)),
            ])
            ->defaultSort('commencement_date', 'desc')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading(__('admin.unit_leases.empty_heading'))
            ->emptyStateDescription(__('admin.unit_leases.empty_description'));
    }
}
