<?php

namespace App\Filament\Admin\Resources\CreditNotes\Tables;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Exports\CreditNoteExporter;
use App\Models\CreditNote;
use App\Models\Tenant;
use App\Services\CreditNotePdfService;
use App\Support\BadgeColors;
use App\Support\Exports;
use App\Support\Filament\DateRangeFilter;
use App\Support\Filament\EntitySelectFilter;
use App\Support\Filament\PdfDownloadAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CreditNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['tenant', 'invoice']))
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.credit_note.number'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('tenant.name')
                    ->label(__('admin.tables.credit_note.tenant'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('invoice.number')
                    ->label(__('admin.fields.invoice'))
                    ->placeholder('—')
                    ->fontFamily('mono'),
                TextColumn::make('reason')
                    ->label(__('admin.fields.credit_note_reason'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.credit_note_reason.{$state}"))
                    ->color('gray'),
                TextColumn::make('issue_date')
                    ->label(__('admin.fields.issue_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('total')
                    ->label(__('admin.tables.credit_note.total'))
                    ->money('EGP')
                    ->sortable()
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('applied_amount')
                    ->label(__('admin.tables.credit_note.applied'))
                    ->money('EGP')
                    ->color('info')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('balance')
                    ->label(__('admin.tables.credit_note.balance'))
                    ->money('EGP')
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                    ->weight('bold')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.credit_note.{$state}"))
                    ->color(BadgeColors::of('credit_notes.status')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.credit_note')),
                SelectFilter::make('reason')
                    ->label(__('admin.fields.credit_note_reason'))
                    ->options(fn () => __('admin.enums.credit_note_reason')),
                EntitySelectFilter::make('tenant_id')
                    ->label(__('admin.filters.tenant'))
                    ->relationship('tenant')
                    ->entity(Tenant::class),
                DateRangeFilter::make('issue_date', null, name: 'issue_date_range'),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->headerActions([
                ExportAction::make()
                    ->exporter(CreditNoteExporter::class)
                    ->label(__('admin.actions.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => Exports::allowed(CreditNoteResource::class))
                    ->authorize(fn (): bool => Exports::allowed(CreditNoteResource::class)),
            ])
            ->recordActions([
                LedgerEntryAction::make(),
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => CreditNoteResource::canView($record))
                    ->authorize(fn ($record) => CreditNoteResource::canView($record)),
                PdfDownloadAction::make('downloadPdf')
                    ->service(CreditNotePdfService::class)
                    ->recipient(fn (CreditNote $record) => $record->tenant)
                    ->authorize(fn (CreditNote $record) => CreditNoteResource::canView($record)),
                EditAction::make()
                    ->visible(fn ($record) => CreditNoteResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(CreditNoteExporter::class)
                        ->label(__('admin.actions.export'))
                        ->visible(fn (): bool => Exports::allowed(CreditNoteResource::class))
                        ->authorize(fn (): bool => Exports::allowed(CreditNoteResource::class)),
                    DeleteBulkAction::make()
                        ->visible(fn () => CreditNoteResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('issue_date', 'desc')
            ->emptyStateIcon('heroicon-o-receipt-refund')
            ->emptyStateHeading(__('admin.empty.credit_notes.heading'))
            ->emptyStateDescription(__('admin.empty.credit_notes.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.credit_notes.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
