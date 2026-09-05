<?php

namespace App\Filament\Admin\Resources\OwnerStatementRuns\Tables;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use App\Models\OwnerStatementRun;
use App\Services\OwnerAccounting\BuildOwnerPackService;
use App\Services\OwnerAccounting\OwnerStatementPdfService;
use App\Support\Filament\PdfDownloadAction;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OwnerStatementRunsTable
{
    /**
     * One line per P&L account from the frozen snapshot — an ARRAY, never a "\n"-joined string.
     *
     * A `TextEntry` renders its state as HTML, where a newline is whitespace: with a single scalar
     * state Filament emits ONE `<div>` (`TextEntry::render()`), and nothing in its stylesheet sets
     * `white-space`. So the itemised owner P&L ran together on one line — "Base rent — EGP
     * 100,000.00 Service charge — EGP 25,000.00 Cleaning — EGP 18,000.00" — which is what an owner
     * reads to understand what they were paid, and the only place in the app that showed a P&L as a
     * paragraph; the PDF beside it has always drawn a proper table (SW-121).
     *
     * Filament's own answer to a list is an array state plus `listWithLineBreaks()`, which routes to
     * the `<ul>` branch. Passing an array is therefore not a formatting preference — it is the
     * contract that branch is selected by.
     *
     * The names come from `OwnerStatementRun::breakdownRows()`, the ONE reading of that column, so
     * this panel and the statement PDF can never name an account in different languages.
     *
     * @return array<int, string>
     */
    public static function workingLines(OwnerStatementRun $run, string $side): array
    {
        $rows = $run->breakdownRows($side);

        if ($rows === []) {
            return [__('admin.owner_statements.pdf.none')];
        }

        // An expense is parenthesised, exactly as the statement PDF prints it. On a panel that lists
        // revenue and cost one under the other, a cost that looks like income is the one misreading
        // that changes what the owner thinks they earned.
        $wrap = $side === 'expense'
            ? fn (string $money): string => '('.$money.')'
            : fn (string $money): string => $money;

        return array_map(
            fn (array $row): string => $row['name'].' — '.$wrap('EGP '.number_format($row['amount'], 2)),
            $rows,
        );
    }

    /**
     * The "View working" panel: the itemised P&L, each side under its own frozen total.
     *
     * Lifted out of the action's `schema()` closure so it can be read without mounting a modal — the
     * same seam `PdfDocument::html()` exists for, and for the same reason: a test that has to inflate
     * rendered HTML to find out whether the working is on one line or four will not be written, and
     * the one written instead proves nothing.
     *
     * @return array<int, TextEntry>
     */
    public static function workingSchema(OwnerStatementRun $record): array
    {
        $money = fn (float|string|null $amount): string => 'EGP '.number_format((float) $amount, 2);

        return [
            TextEntry::make('revenue_working')
                ->label(__('admin.owner_statements.pdf.revenue'))
                ->state(fn (): array => self::workingLines($record, 'revenue'))
                ->listWithLineBreaks()
                // The side's own total, from the same frozen snapshot the lines came from — the
                // subtotal rows the PDF has always carried. Without them the panel showed a list of
                // accounts and a net, with nothing the reader could add up in between.
                ->helperText(__('admin.owner_statements.fields.total_revenue').' — '.$money($record->total_revenue)),
            TextEntry::make('expense_working')
                ->label(__('admin.owner_statements.pdf.expenses'))
                ->state(fn (): array => self::workingLines($record, 'expense'))
                ->listWithLineBreaks()
                ->helperText(__('admin.owner_statements.fields.total_expense').' — ('.$money($record->total_expense).')'),
            TextEntry::make('net_working')
                ->label(__('admin.owner_statements.fields.net_operating_income'))
                ->state(fn (): string => $money($record->net_operating_income))
                ->helperText(__('admin.owner_statements.pdf.net_hint')),
        ];
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'accountingPeriod', 'statements.owner']))
            ->columns([
                TextColumn::make('reference')->label(__('admin.owner_statements.fields.reference'))->searchable()->sortable(),
                TextColumn::make('asset.name')->label(__('admin.owner_statements.fields.property'))->toggleable(),
                TextColumn::make('period_end')->label(__('admin.owner_statements.fields.period'))->date('M Y')->sortable(),
                TextColumn::make('owner')
                    ->label(__('admin.owner_statements.fields.owner'))
                    ->getStateUsing(fn (OwnerStatementRun $r) => $r->statements->first()?->owner?->name ?? '—')
                    ->toggleable(),
                TextColumn::make('net_distributable')->label(__('admin.owner_statements.fields.net_distributable'))->money('EGP')->alignRight()->sortable()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('paid')
                    ->label(__('admin.owner_statements.fields.paid_to_date'))
                    ->getStateUsing(fn (OwnerStatementRun $r) => (float) ($r->statements->first()?->paid_to_date ?? 0))
                    ->money('EGP')->alignRight()->toggleable(),
                TextColumn::make('status')
                    ->label(__('admin.owner_statements.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.owner_statements.statuses.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        OwnerStatementRun::STATUS_FINALISED => 'success',
                        OwnerStatementRun::STATUS_SUPERSEDED => 'gray',
                        default => 'warning',
                    }),
                // Whether the owner actually HAS it. The run's own status stops at "finalised" —
                // Send marks the child statement — so without this column a sent statement and one
                // still sitting in the operator's queue looked identical, and the only tell was a
                // hover button that had disappeared.
                TextColumn::make('sent_at')
                    ->label(__('admin.owner_statements.fields.sent_at'))
                    ->getStateUsing(fn (OwnerStatementRun $r) => $r->statements->first()?->sent_at)
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('admin.owner_statements.not_sent'))
                    ->color(fn (OwnerStatementRun $r) => $r->statements->first()?->sent_at ? 'success' : 'gray'),
                TextColumn::make('version')->label(__('admin.owner_statements.fields.version'))->alignRight()->toggleable(isToggledHiddenByDefault: true),
            ])
            // Newest PERIOD first, not newest keyed. A run for an earlier month can be raised
            // later — a revision, or a catch-up — so insertion order and period order genuinely
            // differ, and it is the period the owner asks about.
            ->defaultSort('period_end', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.owner_statements.fields.status'))
                    ->options(fn () => collect(OwnerStatementRun::STATUSES)
                        ->mapWithKeys(fn (string $s) => [$s => __("admin.owner_statements.statuses.{$s}")])->all()),
            ])
            ->recordActions([
                // **What this document did to the books, from the document.** CHANGE-IMPACT-PLAN
                // §6.1 built the panel and mounted it on five tables; D4 extended it to the Edit
                // headers. These six sources have an operator screen and had neither — so the one
                // question a derived ledger makes people ask ("what happened to my entry?") had no
                // answer here. Read-only and gated on `general_ledger.view`.
                LedgerEntryAction::make(),

                // Download the owner's statement PDF (operator or the owner themselves).
                // "View working" — the itemized P&L behind the net, so an operator (and the owner)
                // can see WHAT the revenue was and WHERE the expenses went, not just three totals.
                // Native infolist, reading the frozen snapshot.
                Action::make('breakdown')
                    ->label(__('admin.owner_statements.actions.view_working'))
                    ->icon('heroicon-o-calculator')->color('gray')
                    ->modalSubmitAction(false)
                    ->schema(fn (OwnerStatementRun $record) => self::workingSchema($record)),

                PdfDownloadAction::make('download_pdf')
                    ->label(__('admin.owner_statements.actions.download_pdf'))
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    // This is the document Jawad receives — an account of his own money rendered by
                    // his managing agent — so it follows HIS language, not the clerk's.
                    ->recipient(fn (OwnerStatementRun $record) => $record->statements()->first()?->owner)
                    ->document(function (OwnerStatementRun $record, string $locale): string {
                        $statement = $record->statements()->first();
                        abort_unless($statement !== null, 404);

                        return app(OwnerStatementPdfService::class)->build($statement, $locale);
                    })
                    ->filename(function (OwnerStatementRun $record): string {
                        $statement = $record->statements()->first();
                        abort_unless($statement !== null, 404);

                        return app(OwnerStatementPdfService::class)->filename($statement);
                    })
                    ->visible(fn (OwnerStatementRun $r) => $r->statements->first() !== null && OwnerStatementRunResource::canViewStatements())
                    ->authorize(fn (OwnerStatementRun $r) => OwnerStatementRunResource::canViewStatements()),

                // ── The evidence behind the statement, in one file (RP-08) ─────────────────────
                // The statement says what the owner is owed; the pack says how each of their malls
                // traded, who is in them and who has not paid. Assembling it by hand meant opening
                // five reports, setting the property on each and attaching five files — per owner,
                // per month, with a chance at every step of attaching the wrong property's file.
                Action::make('download_pack')
                    ->label(__('admin.owner_pack.build'))
                    ->icon('heroicon-o-archive-box-arrow-down')->color('gray')
                    ->visible(fn (OwnerStatementRun $r) => $r->statements->first()?->owner !== null
                        && OwnerStatementRunResource::canViewStatements())
                    ->authorize(fn (OwnerStatementRun $r) => OwnerStatementRunResource::canViewStatements())
                    ->action(function (OwnerStatementRun $record) {
                        abort_unless(OwnerStatementRunResource::canViewStatements(), 403);

                        $owner = $record->statements()->first()?->owner;
                        abort_unless($owner !== null, 404);

                        $path = app(BuildOwnerPackService::class)->build(
                            $owner,
                            CarbonImmutable::parse($record->period_start),
                            CarbonImmutable::parse($record->period_end),
                        );

                        // deleteFileAfterSend: the pack is a derived artefact rebuilt on demand, and
                        // leaving one zip per owner per month in storage would grow without anybody
                        // owning the cleanup.
                        return response()->download($path)->deleteFileAfterSend(true);
                    }),

            ])
            ->emptyStateIcon('heroicon-o-document-chart-bar')
            ->emptyStateHeading(__('admin.empty.owner_statement_runs.heading'))
            ->emptyStateDescription(__('admin.empty.owner_statement_runs.description'));
    }
}
