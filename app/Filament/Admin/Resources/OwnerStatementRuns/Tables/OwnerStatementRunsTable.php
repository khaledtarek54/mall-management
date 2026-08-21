<?php

namespace App\Filament\Admin\Resources\OwnerStatementRuns\Tables;

use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use App\Models\Disbursement;
use App\Models\OwnerStatement;
use App\Models\OwnerStatementRun;
use App\Models\PaymentMethod;
use App\Notifications\OwnerStatementSentNotification;
use App\Services\OwnerAccounting\BuildOwnerPackService;
use App\Services\OwnerAccounting\DisbursementService;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\OwnerAccounting\OwnerStatementPdfService;
use App\Services\OwnerAccounting\ReviseOwnerStatementRunService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OwnerStatementRunsTable
{
    private static function notifyFailure(\Throwable $e): void
    {
        Notification::make()->title($e->getMessage())->danger()->send();
    }

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
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.owner_statements.fields.status'))
                    ->options(fn () => collect(OwnerStatementRun::STATUSES)
                        ->mapWithKeys(fn (string $s) => [$s => __("admin.owner_statements.statuses.{$s}")])->all()),
            ])
            ->recordActions([
                // Finalise a draft — posts the distribution accrual (recompute-then-freeze).
                Action::make('finalise')
                    ->label(__('admin.owner_statements.actions.finalise'))
                    ->icon('heroicon-o-lock-closed')->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (OwnerStatementRun $r) => $r->isDraft() && OwnerStatementRunResource::canFinalise())
                    ->authorize(fn (OwnerStatementRun $r) => OwnerStatementRunResource::canFinalise())
                    ->schema([
                        DatePicker::make('posting_date')
                            ->label(__('admin.owner_statements.fields.posting_date'))
                            ->default(fn (OwnerStatementRun $r) => $r->period_end),
                    ])
                    ->action(function (OwnerStatementRun $record, array $data): void {
                        abort_unless(OwnerStatementRunResource::canFinalise(), 403);
                        try {
                            app(FinaliseOwnerStatementRunService::class)->finalise($record, auth()->user(), $data['posting_date'] ?? null);
                        } catch (\DomainException $e) {
                            self::notifyFailure($e);

                            return;
                        }
                        Notification::make()->title(__('admin.owner_statements.notices.finalised'))->success()->send();
                    }),

                // Revise a finalised run — supersede it + finalise a fresh version.
                Action::make('revise')
                    ->label(__('admin.owner_statements.actions.revise'))
                    ->icon('heroicon-o-arrow-path')->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (OwnerStatementRun $r) => $r->isFinalised() && ! $r->hasActiveDisbursements() && OwnerStatementRunResource::canRevise())
                    ->authorize(fn (OwnerStatementRun $r) => OwnerStatementRunResource::canRevise())
                    ->action(function (OwnerStatementRun $record): void {
                        abort_unless(OwnerStatementRunResource::canRevise(), 403);
                        try {
                            app(ReviseOwnerStatementRunService::class)->revise($record, auth()->user());
                        } catch (\DomainException $e) {
                            self::notifyFailure($e);

                            return;
                        }
                        Notification::make()->title(__('admin.owner_statements.notices.revised'))->success()->send();
                    }),

                // Schedule a payout against the (sole, v1) owner statement's outstanding balance.
                Action::make('schedule')
                    ->label(__('admin.disbursements.actions.schedule'))
                    ->icon('heroicon-o-banknotes')->color('primary')
                    ->visible(fn (OwnerStatementRun $r) => $r->isFinalised()
                        && (float) ($r->statements->first()?->outstanding() ?? 0) > 0
                        && OwnerStatementRunResource::canSchedule())
                    ->authorize(fn (OwnerStatementRun $r) => OwnerStatementRunResource::canSchedule())
                    ->schema([
                        TextInput::make('amount')
                            ->label(__('admin.disbursements.fields.amount'))
                            ->numeric()->minValue(0.01)
                            ->default(fn (OwnerStatementRun $r) => $r->statements->first()?->outstanding())
                            ->required(),
                        Select::make('method')
                            ->label(__('admin.disbursements.fields.method'))
                            // The catalogue, not a constant: an operator who activates a rail must
                            // see it here without a deploy, and a new rail has no lang key.
                            ->options(fn () => PaymentMethod::optionsFor('disbursements.method', 'admin.disbursements.methods'))
                            ->default(Disbursement::METHOD_BANK_TRANSFER)
                            ->required()->native(false),
                    ])
                    ->action(function (OwnerStatementRun $record, array $data): void {
                        abort_unless(OwnerStatementRunResource::canSchedule(), 403);
                        $statement = $record->statements()->first();
                        if (! $statement) {
                            self::notifyFailure(new \DomainException(__('admin.owner_statements.statements')));

                            return;
                        }
                        try {
                            app(DisbursementService::class)->schedule($statement, (float) $data['amount'], $data['method'], auth()->user());
                        } catch (\DomainException $e) {
                            self::notifyFailure($e);

                            return;
                        }
                        Notification::make()->title(__('admin.disbursements.notices.scheduled'))->success()->send();
                    }),

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

                Action::make('download_pdf')
                    ->label(__('admin.owner_statements.actions.download_pdf'))
                    ->icon('heroicon-o-document-arrow-down')->color('gray')
                    ->visible(fn (OwnerStatementRun $r) => $r->statements->first() !== null && OwnerStatementRunResource::canViewStatements())
                    ->authorize(fn (OwnerStatementRun $r) => OwnerStatementRunResource::canViewStatements())
                    ->action(function (OwnerStatementRun $record) {
                        abort_unless(OwnerStatementRunResource::canViewStatements(), 403);
                        $statement = $record->statements()->first();
                        abort_unless($statement !== null, 404);
                        $svc = app(OwnerStatementPdfService::class);
                        $pdf = $svc->build($statement);

                        return response()->streamDownload(
                            fn () => print ($pdf),
                            $svc->filename($statement),
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),

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

                // Send the finalised statement to the owner (marks it sent + bells the owner).
                Action::make('send')
                    ->label(__('admin.owner_statements.actions.send'))
                    ->icon('heroicon-o-paper-airplane')->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (OwnerStatementRun $r) => $r->isFinalised()
                        && ($s = $r->statements->first()) !== null
                        && $s->status !== OwnerStatement::STATUS_SENT
                        && OwnerStatementRunResource::canSend())
                    ->authorize(fn (OwnerStatementRun $r) => OwnerStatementRunResource::canSend())
                    ->action(function (OwnerStatementRun $record): void {
                        abort_unless(OwnerStatementRunResource::canSend(), 403);
                        $statement = $record->statements()->first();
                        if (! $statement) {
                            self::notifyFailure(new \DomainException(__('admin.owner_statements.statements')));

                            return;
                        }
                        $statement->update(['status' => OwnerStatement::STATUS_SENT, 'sent_at' => now()]);
                        $statement->owner?->notify(new OwnerStatementSentNotification($statement));
                        Notification::make()->title(__('admin.owner_statements.notices.sent'))->success()->send();
                    }),
            ])
            ->emptyStateIcon('heroicon-o-document-chart-bar')
            ->emptyStateHeading(__('admin.empty.owner_statement_runs.heading'))
            ->emptyStateDescription(__('admin.empty.owner_statement_runs.description'));
    }
}
