<?php

namespace App\Filament\Portal\Resources\Leases\Tables;

use App\Filament\Portal\Actions\LeaseActions;
use App\Support\BadgeColors;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LeasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.fields.reference'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('unit.code')
                    ->label(__('admin.resources.unit.singular'))
                    ->badge(),
                TextColumn::make('unit.asset.name')
                    ->label(__('admin.fields.property'))
                    ->toggleable(),
                TextColumn::make('base_rent_monthly')
                    ->label(__('admin.fields.base_rent_monthly'))
                    ->money('EGP')
                    ->alignRight(),
                TextColumn::make('expiry_date')
                    ->label(__('admin.fields.expiry_date'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.lease.{$state}"))
                    ->color(BadgeColors::of('leases.status')),
            ])
            ->recordActions([
                ViewAction::make(),
                // The tenant's own signed lease document, if the operator has uploaded one. Private
                // disk — streamed via the media's own response, never a public URL.
                ...LeaseActions::all(),
            ])
            // Most recently commenced first — the same order the operator's own lease list uses.
            // This sorted by insertion, so a tenant and the operator could be looking at the same
            // two tenancies in two different orders.
            ->defaultSort('commencement_date', 'desc')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading(__('admin.portal.lease.empty_heading'))
            ->emptyStateDescription(__('admin.portal.lease.empty_description'));
    }
}
