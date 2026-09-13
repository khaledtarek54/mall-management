<?php

namespace App\Filament\Exports;

use App\Models\LedgerAccount;
use App\Support\DataTransferNotice;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * The chart of accounts in a spreadsheet (the reports audit, 2026-09-12).
 *
 * The chart was IMPORTABLE (EG-28) and not exportable, so the accountant could load their own
 * chart and never take it back out to review, renumber or hand to an auditor. This file round-trips
 * through `LedgerAccountImporter`: every column the importer requires is here under the importer's
 * own label, in both languages (the exporter↔importer gate), the classification columns carry
 * their CODES rather than their translated labels, because a code is what the importer accepts, and
 * the two flags carry `1`/`0` (see below). `parent.code` is informational — the importer derives the
 * parent from the code itself. `TheAccountantsRegistersExportTest` feeds an exported branch and an
 * inactive leaf back through the importer.
 */
class LedgerAccountExporter extends Exporter
{
    protected static ?string $model = LedgerAccount::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('code')->label(__('admin.fields.account_code')),
            ExportColumn::make('name_en')->label(__('admin.fields.account_name_en')),
            ExportColumn::make('name_ar')->label(__('admin.fields.account_name_ar')),
            ExportColumn::make('type')->label(__('admin.fields.account_type')),
            ExportColumn::make('parent.code')->label(__('admin.fields.parent_account')),
            ExportColumn::make('normal_balance')->label(__('admin.fields.normal_balance')),
            ExportColumn::make('cash_flow_section')->label(__('admin.fields.cash_flow_section')),
            ExportColumn::make('statement_section')->label(__('admin.fields.statement_section')),
            // As `1`/`0`, never Filament's own rendering of a boolean: `getFormattedState()` coerces
            // FALSE to an EMPTY cell, and the importer reads a blank as null — so the file did not
            // re-import 101 of the shipped chart's 169 rows (every branch and every inactive leaf,
            // `NOT NULL constraint failed` with no message on the failed-rows sheet). Found by review,
            // by feeding an exported row back through the importer.
            ExportColumn::make('is_postable')->label(__('admin.fields.is_postable'))
                ->state(fn (LedgerAccount $record): int => $record->is_postable ? 1 : 0),
            ExportColumn::make('is_active')->label(__('admin.fields.is_active'))
                ->state(fn (LedgerAccount $record): int => $record->is_active ? 1 : 0),
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
