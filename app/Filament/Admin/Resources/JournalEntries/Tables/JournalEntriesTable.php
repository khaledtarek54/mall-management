<?php

namespace App\Filament\Admin\Resources\JournalEntries\Tables;

use App\Filament\Admin\Resources\JournalEntries\JournalEntryResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
            ->modifyQueryUsing(fn ($query) => $query->withSum('lines as total_debit', 'debit')->with('asset'))
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
