<?php

namespace App\Filament\Admin\Pages\Concerns;

use App\Filament\Admin\Pages\GeneralLedger;
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
                    // What this line is made of. A statement whose figures cannot be opened is
                    // correct and terminal — the numbers are right, and there is no way to ask
                    // where they came from without leaving the report and rebuilding its filters.
                    'account_id' => $row['account_id'] ?? null,
                ];
            }

            $records[] = [
                'id' => 'r'.$i++,
                'section' => $sectionLabel,
                'code' => null,
                'account' => $section['total_label'],
                'amount' => round((float) $section['total'], 2),
                'is_total' => true,
                // A total is not an account, so there is nothing to open. Deliberately null rather
                // than absent, so the column's URL closure has one shape for every row.
                'account_id' => null,
            ];
        }

        return $records;
    }

    /** The common column/group configuration for a statement table. */
    /**
     * The general-ledger link for a statement row, carrying the report's own scope.
     *
     * Null for a total (nothing to open) and for a row with no account — an aggregate the report
     * assembled rather than a ledger account. Null renders as plain text, which reads as
     * information; a dead link reads as a broken screen.
     */
    protected function ledgerUrlFor(array $record): ?string
    {
        if ($record['is_total'] || blank($record['account_id'] ?? null)) {
            return null;
        }

        return GeneralLedger::getUrl(array_filter([
            'accountId' => $record['account_id'],
            'year' => $this->year ?? null,
            'period' => $this->period ?? null,
            'assetId' => $this->assetId ?? null,
        ], fn ($value) => filled($value)));
    }

    /**
     * @param  bool  $comparative  add the prior / change / change-% columns (RP-06)
     */
    protected function statementTable(Table $table, bool $comparative = false): Table
    {
        return $table
            ->columns(array_filter([
                TextColumn::make('code')
                    ->label(__('admin.tables.ledger_account.code'))
                    ->fontFamily('mono')
                    ->size('sm')
                    ->placeholder(''),
                TextColumn::make('account')
                    ->label(__('admin.tables.ledger_account.account'))
                    // The total line is the one an eye should land on.
                    ->weight(fn (array $record): string => $record['is_total'] ? 'bold' : 'normal')
                    // Into the general ledger for THIS account, over the same period and property
                    // the statement was run for. Landing on "this year, all properties" would
                    // answer a different question from the one that was clicked.
                    ->url(fn (array $record): ?string => $this->ledgerUrlFor($record))
                    ->color(fn (array $record): ?string => $this->ledgerUrlFor($record) ? 'primary' : null),
                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP')
                    ->alignEnd()
                    ->weight(fn (array $record): string => $record['is_total'] ? 'bold' : 'normal'),
                // ── The comparison, when one was asked for (RP-06) ──────────────────────────────
                // A single period's P&L says what happened; it cannot say whether that is normal.
                // 180,000 of maintenance is unremarkable next to 175,000 last month and alarming
                // next to 40,000, and the statement could not tell those apart.
                $comparative ? TextColumn::make('prior')
                    ->label(__('admin.reports.prior'))
                    ->money('EGP')
                    ->alignEnd()
                    ->weight(fn (array $record): string => $record['is_total'] ? 'bold' : 'normal') : null,
                $comparative ? TextColumn::make('change')
                    ->label(__('admin.reports.change'))
                    ->money('EGP')
                    ->alignEnd()
                    // Colour by DIRECTION only, never by "good". On an income statement a rise is
                    // welcome in revenue and unwelcome in expenses, and the table does not know
                    // which section a reader is looking at — claiming otherwise would be worse than
                    // staying neutral.
                    ->color(fn (array $record): ?string => match (true) {
                        ($record['change'] ?? 0) > 0 => 'success',
                        ($record['change'] ?? 0) < 0 => 'danger',
                        default => null,
                    })
                    ->weight(fn (array $record): string => $record['is_total'] ? 'bold' : 'normal') : null,
                $comparative ? TextColumn::make('change_pct')
                    ->label(__('admin.reports.change_pct'))
                    ->alignEnd()
                    // Null, not 0%, when the prior figure was zero: a rise from nothing has no
                    // percentage, and printing one ("+100%", "∞") invents a number the books do not
                    // support. The em dash says "not applicable" and is the honest answer.
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : number_format((float) $state, 1).'%')
                    ->weight(fn (array $record): string => $record['is_total'] ? 'bold' : 'normal') : null,
            ]))
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
