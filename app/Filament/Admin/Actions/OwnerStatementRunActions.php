<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use App\Models\Disbursement;
use App\Models\OwnerStatement;
use App\Models\OwnerStatementRun;
use App\Models\PaymentMethod;
use App\Notifications\OwnerStatementSentNotification;
use App\Services\OwnerAccounting\DisbursementService;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\OwnerAccounting\ReviseOwnerStatementRunService;
use App\Support\Filament\BankAccountField;
use App\Support\RowActionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * **Everything you can DO to a owner statement run, defined once.**
 *
 * `finalise`, `revise`, `schedule` and `send` lived inline in `OwnerStatementRunsTable`,
 * so they were reachable from the LIST and the record's
 * own page carried Delete and little else — backwards from the record-hub architecture this
 * project took from Yardi: **the list finds, the record acts**. Defined here, composed onto the
 * record page, so the two surfaces can never drift.
 *
 * Safe to move, and measured rather than assumed: every role that can perform these acts can open
 * the page it moved to. Four resources failed that check — an act held by a role that
 * deliberately lacks `{module}.edit` — and kept their verbs on the row; see
 * {@see RowActionPolicy}.
 */
class OwnerStatementRunActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
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

                    // Which bank account this money moved through — optional, and null means the rail
                    // decides. Set it and the posting lands in THAT account's chart account, which is
                    // what lets a mall banking in two places reconcile either one.
                    BankAccountField::make(),
                ])
                ->action(function (OwnerStatementRun $record, array $data): void {
                    abort_unless(OwnerStatementRunResource::canSchedule(), 403);
                    $statement = $record->statements()->first();
                    if (! $statement) {
                        self::notifyFailure(new \DomainException(__('admin.owner_statements.statements')));

                        return;
                    }
                    try {
                        app(DisbursementService::class)->schedule($statement, (float) $data['amount'], $data['method'], auth()->user(), $data['bank_account_id'] ?? null);
                    } catch (\DomainException $e) {
                        self::notifyFailure($e);

                        return;
                    }
                    Notification::make()->title(__('admin.disbursements.notices.scheduled'))->success()->send();
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
        ];
    }

    public static function notifyFailure(\Throwable $e): void
    {
        Notification::make()->title($e->getMessage())->danger()->send();
    }
}
