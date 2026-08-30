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
    /** One line per P&L account from the frozen snapshot (localized), or a dash for a legacy run. */
    private static function breakdownLines(OwnerStatementRun $run, string $side): string
    {
        $isRtl = app()->getLocale() === 'ar';
        $rows = (array) (($run->income_breakdown ?? [])[$side] ?? []);

        if ($rows === []) {
            return __('admin.owner_statements.pdf.none');
        }

        return collect($rows)->map(function (array $r) use ($isRtl) {
            $name = $isRtl ? ($r['name_ar'] ?? $r['name_en'] ?? $r['code']) : ($r['name_en'] ?? $r['name_ar'] ?? $r['code']);

            return $name.' — EGP '.number_format((float) ($r['amount'] ?? 0), 2);
        })->join("\n");
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
                    ->schema(fn (OwnerStatementRun $record) => [
                        TextEntry::make('revenue_working')
                            ->label(__('admin.owner_statements.pdf.revenue'))
                            ->state(fn () => self::breakdownLines($record, 'revenue')),
                        TextEntry::make('expense_working')
                            ->label(__('admin.owner_statements.pdf.expenses'))
                            ->state(fn () => self::breakdownLines($record, 'expense')),
                        TextEntry::make('net_working')
                            ->label(__('admin.owner_statements.fields.net_operating_income'))
                            ->state(fn () => 'EGP '.number_format((float) $record->net_operating_income, 2))
                            ->helperText(__('admin.owner_statements.pdf.net_hint')),
                    ]),

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
