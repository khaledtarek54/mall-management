<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use App\Models\PostDatedCheque;
use App\Services\BillBouncedChequeFeeService;
use App\Services\PostDatedChequeService;
use App\Settings\BillingSettings;
use App\Support\RowActionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;

/**
 * **Everything you can DO to a post-dated cheque, defined once.**
 *
 * `deposit`, `clear`, `bounce`, `chargensffee` and `cancel` lived inline in `PostDatedChequesTable`,
 * so the act was reachable from the LIST and the record's
 * own page carried Delete and little else — backwards from the record-hub architecture this
 * project took from Yardi: **the list finds, the record acts**. Defined here, composed onto the
 * record page, so the two surfaces can never drift.
 *
 * Safe to move, and measured rather than assumed: every role that can perform this act can open
 * the page it moved to. Four resources failed that check — an act held by a role that
 * deliberately lacks `{module}.edit` — and kept their verbs on the row; see
 * {@see RowActionPolicy}.
 */
class PostDatedChequeActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            Action::make('deposit')
                ->label(__('admin.post_dated_cheques.actions.deposit'))
                ->icon('heroicon-o-arrow-up-tray')->color('info')
                ->visible(fn (PostDatedCheque $r) => in_array($r->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_BOUNCED], true) && PostDatedChequeResource::canManage())
                ->authorize(fn (PostDatedCheque $r) => PostDatedChequeResource::canManage())
                ->action(function (PostDatedCheque $record): void {
                    abort_unless(PostDatedChequeResource::canManage(), 403);
                    self::run(fn () => app(PostDatedChequeService::class)->deposit($record), 'deposited');
                }),
            Action::make('clear')
                ->label(__('admin.post_dated_cheques.actions.clear'))
                ->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn (PostDatedCheque $r) => in_array($r->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_DEPOSITED], true) && PostDatedChequeResource::canClear())
                ->authorize(fn (PostDatedCheque $r) => PostDatedChequeResource::canClear())
                ->schema([
                    DatePicker::make('cleared_on')
                        ->label(__('admin.post_dated_cheques.fields.cleared_on'))
                        ->default(fn () => now()->toDateString())
                        ->maxDate(fn () => now())
                        ->required(),
                ])
                ->action(function (PostDatedCheque $record, array $data): void {
                    abort_unless(PostDatedChequeResource::canClear(), 403);
                    self::run(fn () => app(PostDatedChequeService::class)->clear($record, auth()->user(), $data['cleared_on']), 'cleared');
                }),
            Action::make('bounce')
                ->label(__('admin.post_dated_cheques.actions.bounce'))
                ->icon('heroicon-o-x-circle')->color('danger')
                ->requiresConfirmation()
                ->visible(fn (PostDatedCheque $r) => in_array($r->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_DEPOSITED], true) && PostDatedChequeResource::canManage())
                ->authorize(fn (PostDatedCheque $r) => PostDatedChequeResource::canManage())
                ->action(function (PostDatedCheque $record): void {
                    abort_unless(PostDatedChequeResource::canManage(), 403);
                    self::run(fn () => app(PostDatedChequeService::class)->bounce($record), 'bounced');
                }),
            // Charging for a bounce is a DECISION, not a consequence — a landlord may waive it
            // for a tenant whose cheque bounced once in five years. So it is its own action,
            // exactly as billing a violation fine is separate from recording the violation.
            // Hidden until a fee is configured: an action that can only refuse is noise.
            Action::make('chargeNsfFee')
                ->label(__('admin.post_dated_cheques.nsf_fee'))
                ->icon('heroicon-o-receipt-percent')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (PostDatedCheque $r): bool => $r->status === PostDatedCheque::STATUS_BOUNCED
                    && $r->nsf_fee_invoice_id === null
                    && (float) app(BillingSettings::class)->nsf_fee_amount > 0
                    && PostDatedChequeResource::canManage())
                ->authorize(fn (PostDatedCheque $r): bool => PostDatedChequeResource::canManage())
                ->action(function (PostDatedCheque $record): void {
                    abort_unless(PostDatedChequeResource::canManage(), 403);

                    try {
                        $invoice = app(BillBouncedChequeFeeService::class)->bill($record);
                    } catch (\DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title(__('admin.post_dated_cheques.nsf_fee_billed', ['number' => $invoice->number]))
                        ->send();
                }),
            Action::make('cancel')
                ->label(__('admin.post_dated_cheques.actions.cancel'))
                ->icon('heroicon-o-trash')->color('gray')
                ->requiresConfirmation()
                ->visible(fn (PostDatedCheque $r) => ! in_array($r->status, [PostDatedCheque::STATUS_CLEARED, PostDatedCheque::STATUS_CANCELLED], true) && PostDatedChequeResource::canManage())
                ->authorize(fn (PostDatedCheque $r) => PostDatedChequeResource::canManage())
                ->action(function (PostDatedCheque $record): void {
                    abort_unless(PostDatedChequeResource::canManage(), 403);
                    self::run(fn () => app(PostDatedChequeService::class)->cancel($record), 'cancelled');
                }),
        ];
    }

    /** Run a lifecycle transition with the standard try/catch + success toast. */
    public static function run(callable $fn, string $noticeKey): void
    {
        try {
            $fn();
        } catch (\DomainException $e) {
            self::notifyFailure($e);

            return;
        }
        Notification::make()->title(__("admin.post_dated_cheques.notices.{$noticeKey}"))->success()->send();
    }

    public static function notifyFailure(\Throwable $e): void
    {
        Notification::make()->title($e->getMessage())->danger()->send();
    }
}
