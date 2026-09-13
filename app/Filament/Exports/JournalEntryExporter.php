<?php

namespace App\Filament\Exports;

use App\Models\JournalEntry;
use App\Support\DataTransferNotice;
use App\Support\SourceDocumentLabel;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * The journal register in a spreadsheet — one row per ENTRY (the reports audit, 2026-09-12).
 *
 * The accountant's registers had no export at all: journal entries, the chart, supplier bills,
 * expenses, deposits and cheques, while receipts, invoices and credit notes had one. Every ledger
 * system hands the journal listing to a spreadsheet; an auditor's first request is that file.
 *
 * This is the HEADER listing — number, date, narrative, source, property, total, status. The
 * line-level reading (account · debit · credit, with running balances) is the general ledger's own
 * "every account" export, so the two are not one file with two shapes.
 */
class JournalEntryExporter extends Exporter
{
    protected static ?string $model = JournalEntry::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('number')->label(__('admin.tables.journal_entry.number')),
            ExportColumn::make('entry_date')->label(__('admin.fields.entry_date')),
            // The narrative resolved for the reader (EG-36), never the prose frozen at post time.
            ExportColumn::make('description')
                ->label(__('admin.fields.description'))
                ->state(fn (JournalEntry $record): string => $record->displayDescription()),
            // The document behind the entry, named as the register names it — its number, else its
            // kind and id in the reader's language, never a morph alias.
            ExportColumn::make('source')
                ->label(__('admin.ledger_trail.source'))
                ->state(fn (JournalEntry $record): ?string => SourceDocumentLabel::for($record->source, $record->source_type)),
            // A portfolio-level entry (the year-end close) names no property; the list says so in
            // words, and a blank cell beside rows naming a mall reads as a missing property.
            ExportColumn::make('asset.name')->label(__('admin.fields.property'))
                ->state(fn (JournalEntry $record): string => $record->asset?->name ?? __('admin.fields.property_consolidated')),
            // The export runs the list's own query, `withSum` included — `modifyQueryUsing` is a
            // query scope and the subselect survives serialisation into the export job (measured:
            // the export's SQL carries `(select sum(debit) …) as total_debit`). So the loaded figure
            // is read where it is there, and the model's own `totalDebit()` answers where it is not;
            // the first cut ran `lines()->sum()` per row beside a subselect that had already done it.
            ExportColumn::make('total_debit')
                ->label(__('admin.fields.total_debit'))
                ->state(fn (JournalEntry $record): float => $record->total_debit !== null
                    ? round((float) $record->total_debit, 2)
                    : $record->totalDebit()),
            ExportColumn::make('is_manual')->label(__('admin.tables.journal_entry.manual')),
            ExportColumn::make('status')->label(__('admin.tables.common.status')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return DataTransferNotice::forExport($export);
    }

    public function getJobConnection(): ?string
    {
        return config('exports.connection', 'sync');
    }
}
