<?php

namespace App\Filament\Admin\Pages\Concerns;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * Shared rendering for the three financial statements — Balance Sheet, Income
 * Statement, Cash Flow.
 *
 * All three are the same shape: a handful of named SECTIONS (Assets /
 * Liabilities / Equity, Revenue / Expenses, Operating / Investing / Financing),
 * each a list of account lines that foots to a section total. This turns that
 * shape into one native Filament table so the pages stop hand-writing <table>
 * markup with inline colours.
 *
 * Two deliberate choices:
 *
 * - Section totals are emitted as REAL ROWS (`is_total`), not summarizers.
 *   Filament computes a summarizer per group off the SQL query, and these
 *   tables are `records()`-backed (the numbers are aggregates the report
 *   service computed, not a row set) — so a per-group summarizer has nothing
 *   to aggregate. A printed statement reads with its totals inline anyway.
 *
 * - Rows arrive pre-ordered by section and grouping keys off the record, since
 *   Group's query-based ordering likewise does not apply to array data.
 */
trait RendersFinancialStatement
{
    /**
     * Flatten `[sectionLabel => ['rows' => Collection, 'total' => float, 'total_label' => string]]`
     * into table records: each section's account lines followed by its total line.
     *
     * @param  array<string, array{rows: Collection, total: float, total_label: string}>  $sections
     * @return array<int, array<string, mixed>>
     */
    protected function statementRecords(array $sections): array
    {
        $locale = app()->getLocale();
        $records = [];
        $i = 0;

        foreach ($sections as $sectionLabel => $section) {
            foreach ($section['rows'] as $row) {
                $records[] = [
                    'id' => 'r'.$i++,
                    'section' => $sectionLabel,
                    'code' => $row['code'] ?? null,
                    'account' => $locale === 'ar' ? ($row['name_ar'] ?? '') : ($row['name_en'] ?? ''),
                    'amount' => round((float) ($row['amount'] ?? 0), 2),
                    'is_total' => false,
                ];
            }

            $records[] = [
                'id' => 'r'.$i++,
                'section' => $sectionLabel,
                'code' => null,
                'account' => $section['total_label'],
                'amount' => round((float) $section['total'], 2),
                'is_total' => true,
            ];
        }

        return $records;
    }

    /** The common column/group configuration for a statement table. */
    protected function statementTable(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.tables.ledger_account.code'))
                    ->fontFamily('mono')
                    ->size('sm')
                    ->placeholder(''),
                TextColumn::make('account')
                    ->label(__('admin.tables.ledger_account.account'))
                    // The total line is the one an eye should land on.
                    ->weight(fn (array $record): string => $record['is_total'] ? 'bold' : 'normal'),
                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP')
                    ->alignEnd()
                    ->weight(fn (array $record): string => $record['is_total'] ? 'bold' : 'normal'),
            ])
            ->groups([
                Group::make('section')
                    ->getKeyFromRecordUsing(fn (array $record): string => $record['section'])
                    ->getTitleFromRecordUsing(fn (array $record): string => $record['section']),
            ])
            ->defaultGroup('section')
            // A statement is one document; paginating it would cut sections in half.
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-document-chart-bar')
            ->emptyStateHeading(__('admin.reports.no_movements'))
            ->emptyStateDescription(__('admin.reports.no_movements_hint'));
    }
}
