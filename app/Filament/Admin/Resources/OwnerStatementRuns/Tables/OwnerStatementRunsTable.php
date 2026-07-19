<?php

namespace App\Filament\Admin\Resources\OwnerStatementRuns\Tables;

use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use App\Models\Disbursement;
use App\Models\OwnerStatement;
use App\Models\OwnerStatementRun;
use App\Notifications\OwnerStatementSentNotification;
use App\Services\OwnerAccounting\DisbursementService;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\OwnerAccounting\OwnerStatementPdfService;
use App\Services\OwnerAccounting\ReviseOwnerStatementRunService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OwnerStatementRunsTable
{
    private static function notifyFailure(\Throwable $e): void
    {
        Notification::make()->title($e->getMessage())->danger()->send();
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
                TextColumn::make('net_distributable')->label(__('admin.owner_statements.fields.net_distributable'))->money('EGP')->alignRight()->sortable(),
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
                            static::notifyFailure($e);

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
                            static::notifyFailure($e);

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
                            ->options(fn () => collect(Disbursement::METHODS)
                                ->mapWithKeys(fn (string $m) => [$m => __("admin.disbursements.methods.{$m}")])->all())
                            ->default(Disbursement::METHOD_BANK_TRANSFER)
                            ->required()->native(false),
                    ])
                    ->action(function (OwnerStatementRun $record, array $data): void {
                        abort_unless(OwnerStatementRunResource::canSchedule(), 403);
                        $statement = $record->statements()->first();
                        if (! $statement) {
                            static::notifyFailure(new \DomainException(__('admin.owner_statements.statements')));

                            return;
                        }
                        try {
                            app(DisbursementService::class)->schedule($statement, (float) $data['amount'], $data['method'], auth()->user());
                        } catch (\DomainException $e) {
                            static::notifyFailure($e);

                            return;
                        }
                        Notification::make()->title(__('admin.disbursements.notices.scheduled'))->success()->send();
                    }),

                // Download the owner's statement PDF (operator or the owner themselves).
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
                            static::notifyFailure(new \DomainException(__('admin.owner_statements.statements')));

                            return;
                        }
                        $statement->update(['status' => OwnerStatement::STATUS_SENT, 'sent_at' => now()]);
                        $statement->owner?->notify(new OwnerStatementSentNotification($statement));
                        Notification::make()->title(__('admin.owner_statements.notices.sent'))->success()->send();
                    }),
            ]);
    }
}
