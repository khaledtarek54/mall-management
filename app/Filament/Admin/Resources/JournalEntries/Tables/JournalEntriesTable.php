<?php

namespace App\Filament\Admin\Resources\JournalEntries\Tables;

use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use Filament\Facades\Filament;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class JournalEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->withSum('lines as total_debit', 'debit')
                // `source` is a morphTo, so eager-loading it is what keeps the new column from
                // issuing a query per row.
                ->with('asset', 'source', 'reversalOf'))
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.journal_entry.number'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('entry_date')
                    ->label(__('admin.fields.entry_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('display_description')
                    ->label(__('admin.fields.description'))
                    ->getStateUsing(fn ($record) => $record->displayDescription())
                    ->wrap()
                    ->limit(60),
                // The other half of the trail. `source_type`/`source_id` and `reversal_of_id` were
                // always stored and never shown, so getting from an entry back to the document that
                // caused it meant reading the description text and searching for the number by hand.
                // The resource is resolved through Filament's own registry rather than a hand-kept
                // model→resource map, which would be one more list to drift.
                TextColumn::make('source')
                    ->label(__('admin.ledger_trail.source'))
                    ->placeholder('—')
                    ->getStateUsing(function ($record) {
                        if ($record->reversal_of_id) {
                            return __('admin.ledger_trail.reversal_of', [
                                'number' => $record->reversalOf?->getAttribute('number') ?? '#'.$record->reversal_of_id,
                            ]);
                        }

                        $source = $record->source;

                        // getAttribute rather than ->number: `source` is a morphTo, so it is a bare
                        // Model to static analysis and every source names itself differently —
                        // invoices and bills carry a `number`, receipts a `reference`.
                        return $source?->getAttribute('number')
                            ?? $source?->getAttribute('reference')
                            ?? ($record->source_type ? class_basename($record->source_type) : null);
                    })
                    ->url(function ($record): ?string {
                        $source = $record->source;
                        if (! $source) {
                            return null;
                        }
                        $resource = Filament::getModelResource($source::class);

                        // No resource (or no edit page on it) means nowhere to send them — a dead
                        // link reads as a broken screen, a plain label reads as information.
                        return $resource && method_exists($resource, 'getUrl')
                            ? rescue(fn () => $resource::getUrl('edit', ['record' => $source]), null, false)
                            : null;
                    })
                    ->color(fn ($record) => $record->reversal_of_id ? 'warning' : 'primary')
                    ->wrap(),
                TextColumn::make('asset.name')
                    ->label(__('admin.fields.property'))
                    ->placeholder(__('admin.fields.property_consolidated'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('total_debit')
                    ->label(__('admin.fields.total_debit'))
                    ->money('EGP')
                    ->alignRight(),
                IconColumn::make('is_manual')
                    ->label(__('admin.tables.journal_entry.manual'))
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.journal_entry.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'posted' => 'success',
                        'void' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.journal_entry')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => JournalEntryResource::canView($record))
                    ->authorize(fn ($record) => JournalEntryResource::canView($record)),
                EditAction::make()
                    ->visible(fn ($record) => JournalEntryResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => JournalEntryResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('entry_date', 'desc')
            ->emptyStateIcon('heroicon-o-book-open')
            ->emptyStateHeading(__('admin.empty.journal_entries.heading'))
            ->emptyStateDescription(__('admin.empty.journal_entries.description'));
    }
}
