<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Models\LeaseOption;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Who already has a claim over this unit — the options that encumber it.
 *
 * An expansion option, a right of first refusal, a right of first offer or a purchase option on
 * SOMEONE ELSE'S lease means this unit is not freely lettable: promise it to a prospect and the
 * option holder has to be dealt with first. `Unit::isEncumbered()` already drove a ⚠ warning in the
 * lease unit picker — but only at the moment of writing a lease, and the warning said *that* the
 * unit was encumbered without saying by whom or until when.
 *
 * This is the answer to "why is this unit flagged?", on the unit itself. Read-only: an option is a
 * clause of a lease and is edited there, which is also where resolving it (exercised / lapsed /
 * waived) belongs.
 *
 * Shows only OPEN options of encumbering types — the relation itself filters, so a lapsed right does
 * not keep a unit looking spoken-for for ever.
 */
class UnitEncumbrancesRelationManager extends RelationManager
{
    protected static string $relationship = 'encumbrances';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.unit_encumbrances.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->searchable(false)
            ->columns([
                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (string $state) => __("admin.lease_options.types.{$state}")),

                TextColumn::make('lease.reference')
                    ->label(__('admin.tables.lease.reference'))
                    ->fontFamily('mono')
                    ->size('xs'),

                // WHO holds the claim — the whole reason to look at this table.
                TextColumn::make('lease.tenant.name')
                    ->label(__('admin.tables.lease.tenant'))
                    ->placeholder('—'),

                TextColumn::make('earliest_notice_date')
                    ->label(__('admin.lease_options.earliest_notice_date'))
                    ->date('d/m/Y')
                    ->placeholder('—'),

                // Until when the unit stays spoken for: past this date with no notice given, the
                // option lapses and the space is free again.
                TextColumn::make('latest_notice_date')
                    ->label(__('admin.lease_options.latest_notice_date'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (?string $state) => $state === null ? 'gray' : 'warning'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (LeaseOption $record): ?string => $record->lease
                        ? LeaseResource::getUrl('edit', ['record' => $record->lease])
                        : null)
                    ->visible(fn (LeaseOption $record): bool => $record->lease !== null
                        && LeaseResource::canEdit($record->lease)),
            ])
            ->defaultSort('latest_notice_date')
            ->emptyStateIcon('heroicon-o-lock-open')
            ->emptyStateHeading(__('admin.unit_encumbrances.empty_heading'))
            ->emptyStateDescription(__('admin.unit_encumbrances.empty_description'));
    }
}
