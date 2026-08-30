<?php

namespace App\Filament\Admin\Resources\RentIndices\Tables;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RentIndicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // No search box. `TableDefaults` gives every table the folded-blob search, and
            // `RentIndex` carries no blob — a box that always returns nothing is indistinguishable
            // from "no such record", which is why it never gets reported as a bug. The register is
            // a dozen rows a year, sorted newest first; scrolling IS the interface.
            ->searchable(false)
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.fields.index_code'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('period')
                    ->label(__('admin.fields.index_period'))
                    ->date('M Y')
                    ->sortable(),

                TextColumn::make('value')
                    ->label(__('admin.fields.index_value'))
                    ->numeric(decimalPlaces: 4)
                    ->sortable(),

                TextColumn::make('published_on')
                    ->label(__('admin.fields.index_published_on'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('notes')
                    ->label(__('admin.fields.notes'))
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                // The register had no row actions at all, so a keyed-wrong figure was correctable
                // only by clicking the row — and `notes`, the field that explains an unusual
                // reading, is truncated to 40 characters in the table and hidden by default. A
                // read-only view is where the whole row is legible.
                ViewAction::make(),

                EditAction::make()
                    ->visible(fn (): bool => self::canWrite())
                    ->authorize(fn (): bool => self::canWrite()),
            ])
            // Newest month first: the figure somebody is about to enter is always the most recent
            // one, and the one they check against is the month before it.
            ->defaultSort('period', 'desc')
            ->emptyStateIcon('heroicon-o-chart-bar')
            ->emptyStateHeading(__('admin.empty.rent_indices.heading'))
            ->emptyStateDescription(__('admin.empty.rent_indices.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.rent_indices.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }

    /** Named once so `visible()` and `authorize()` cannot drift — the double-gate rule. */
    private static function canWrite(): bool
    {
        return auth()->user()?->can('rent_indices.edit') ?? false;

    }
}
