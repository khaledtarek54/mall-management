<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Models\CamExpensePool;
use App\Services\ApplyCamEstimateService;
use App\Services\CamReconciliationService;
use App\Services\SyncCamPoolFromLedgerService;
use App\Support\RowActionPolicy;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * **Everything you can DO to a CAM expense pool, defined once.**
 *
 * `syncfromledger`, `applyestimates`, `generateallocations` and `markreconciled` lived inline in `CamExpensePoolsTable`,
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
class CamExpensePoolActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            // RC-01: pull the year's actual expense straight out of the ledger, so the pool
            // is the sum of the bills rather than a figure re-keyed from a spreadsheet.
            Action::make('syncFromLedger')
                ->label(__('admin.cam.sync_from_ledger'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (CamExpensePool $record) => self::canGenerate($record) && $record->isDerived())
                ->authorize(fn (CamExpensePool $record) => self::canGenerate($record))
                // AUTHZ says who may re-source; READINESS says whether it is still allowed to move.
                // The same split `markReconciled` below already uses, for the same reason: a role
                // that may not sync should not see the button, and one that may should be TOLD why
                // it is not pressable rather than press it and meet a model-level refusal worded
                // for the edit form.
                //
                // Measured at HEAD 2026-09-05: `canGenerate()` keeps this live on a `reconciling`
                // pool, and `CamExpensePool::booted()` freezes both totals the moment one
                // allocation leaves `pending`. `SyncCamPoolFromLedgerService::sync()` is the gate —
                // `disabled()` is a rendering decision — and both read the same predicate, so the
                // sign and the refusal cannot drift.
                ->disabled(fn (CamExpensePool $record) => $record->hasBilledAllocations())
                ->tooltip(fn (CamExpensePool $record) => $record->hasBilledAllocations()
                    ? __('admin.refusals.cam_sync_locked_after_billing')
                    : null)
                ->action(function (CamExpensePool $record): void {
                    abort_unless(self::canGenerate($record), 403);

                    try {
                        $result = app(SyncCamPoolFromLedgerService::class)->sync($record);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    if ($result['expense'] === null && $result['estimate'] === null) {
                        Notification::make()->warning()->title(__('admin.cam.sync_nothing'))->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(__('admin.cam.synced'))
                        ->body(__('admin.cam.synced_body', [
                            'expense' => 'EGP '.number_format((float) $record->fresh()->total_actual_expense, 2),
                            'estimate' => 'EGP '.number_format((float) $record->fresh()->total_estimated_collected, 2),
                        ]))
                        ->send();
                }),
            // RC-05: the loop that was open — nothing ever moved the monthly estimate, so the
            // reconciliation discovered the same shortfall every year.
            Action::make('applyEstimates')
                ->label(__('admin.cam.apply_estimates'))
                ->icon('heroicon-o-forward')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(fn (CamExpensePool $record) => __('admin.cam.apply_estimates_heading', ['year' => $record->period_year]))
                ->modalDescription(fn (CamExpensePool $record) => __('admin.cam.apply_estimates_description', [
                    'next' => (int) $record->period_year + 1,
                ]))
                ->visible(fn (CamExpensePool $record) => in_array($record->status, ['reconciled', 'closed'], true)
                    && CamExpensePoolResource::canEdit($record))
                ->authorize(fn (CamExpensePool $record) => CamExpensePoolResource::canEdit($record))
                ->action(function (CamExpensePool $record): void {
                    abort_unless(CamExpensePoolResource::canEdit($record), 403);

                    try {
                        $result = app(ApplyCamEstimateService::class)->applyForPool($record);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(__('admin.cam.estimates_applied'))
                        ->body(__('admin.cam.estimates_applied_body', [
                            'applied' => $result['applied'],
                            'skipped' => $result['skipped'],
                            'from' => $result['effective_from']->format('d/m/Y'),
                        ]))
                        ->send();
                }),
            Action::make('generateAllocations')
                ->label(__('admin.actions.generate_allocations'))
                ->icon('heroicon-o-calculator')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.generate_allocations_confirm'))
                ->visible(fn (CamExpensePool $record) => self::canGenerate($record))
                ->action(function (CamExpensePool $record): void {
                    abort_unless(self::canGenerate($record), 403);
                    $count = app(CamReconciliationService::class)->generateAllocations($record);
                    $record->update(['status' => 'reconciling']);
                    Notification::make()
                        ->success()
                        ->title(__('admin.notifications.allocations_generated'))
                        ->body(__('admin.notifications.allocations_generated_body', ['count' => $count]))
                        ->send();
                }),
            // YARDI POSTS THE BATCH, NOT THE TENANT. Recovery Reconciliation is reviewed per
            // property and then posted; nobody posts a 39-tenant pool one row at a time. The
            // capability existed behind `cam:reconcile --auto-bill` — a CLI the operator cannot
            // reach, and a flag the scheduled run deliberately does not pass — so the panel offered
            // 39 clicks and no alternative.
            //
            // The per-allocation Bill STAYS. Billing one tenant and holding another back is a real
            // act (a disputed share, a lease in negotiation), and this replaces neither it nor the
            // per-row Void that undoes it.
            Action::make('billAllPending')
                ->label(__('admin.actions.bill_all_pending'))
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->requiresConfirmation()
                // The modal states what is ABOUT to happen in figures, because the difference
                // between a batch you can approve and a button you press hoping is being told how
                // many invoices and how many credit notes it will create.
                ->modalHeading(__('admin.actions.bill_all_pending'))
                ->modalDescription(fn (CamExpensePool $record) => self::batchSummary($record))
                ->visible(fn (CamExpensePool $record) => self::canBillAll($record))
                ->authorize(fn (CamExpensePool $record) => self::canBillAll($record))
                ->action(function (CamExpensePool $record): void {
                    abort_unless(self::canBillAll($record), 403);

                    $r = app(CamReconciliationService::class)->billAllPending($record);

                    if ($r['failed'] > 0) {
                        Notification::make()
                            ->warning()
                            ->title(__('admin.notifications.cam_batch_billed_partial', [
                                'billed' => $r['billed'],
                                'failed' => $r['failed'],
                            ]))
                            ->body(implode("\n", array_slice($r['failures'], 0, 5)))
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(__('admin.notifications.cam_batch_billed', ['count' => $r['billed']]))
                        ->body(__('admin.notifications.cam_batch_billed_body', [
                            'recovered' => $r['recovered'],
                            'credited' => $r['credited'],
                            'fee_only' => $r['fee_only'],
                        ]))
                        ->send();
                }),
            Action::make('markReconciled')
                ->label(__('admin.actions.mark_reconciled'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (CamExpensePool $record) => self::canMarkReconciled($record))
                // AUTHZ says who may close the year; READINESS says whether it is finished. Kept
                // apart because they fail differently: a role that may not close it should not see
                // the button, and one that may should see WHY it is not yet pressable rather than a
                // button that vanished. `disabled()` is refused at dispatch on this Filament
                // version, and the guard in action() is the layer we control.
                ->disabled(fn (CamExpensePool $record) => self::unbilledCount($record) > 0)
                ->tooltip(fn (CamExpensePool $record) => self::unbilledCount($record) > 0
                    ? __('admin.refusals.cam_pool_has_unbilled_allocations', ['count' => self::unbilledCount($record)])
                    : null)
                ->action(function (CamExpensePool $record): void {
                    abort_unless(self::canMarkReconciled($record), 403);

                    self::assertReadyToReconcile($record);

                    $record->update([
                        'status' => 'reconciled',
                        'reconciled_at' => now(),
                        'reconciled_by_user_id' => auth()->id(),
                    ]);
                    Notification::make()->success()->title(__('admin.notifications.pool_reconciled'))->send();
                }),
        ];
    }

    /**
     * The predicate for each write action, named ONCE so visible() (the UI) and action() (the real
     * gate) can't drift. `mountAction()` never checks isVisible(), so a hidden action is still
     * dispatchable by a crafted Livewire call — every write must re-assert in action() (abort_unless).
     * Both the permission AND the status must be re-checked: `viewer`/`owner` hold cam.view (so the
     * pool list renders) but not the action perms, and re-opening a reconciled pool must stay blocked.
     */
    public static function canGenerate(CamExpensePool $record): bool
    {
        return in_array($record->status, ['draft', 'reconciling'], true)
            && (auth()->user()?->can('cam.generate_allocations') ?? false);
    }

    public static function canMarkReconciled(CamExpensePool $record): bool
    {
        return $record->status === 'reconciling'
            && (auth()->user()?->can('cam.mark_reconciled') ?? false);
    }

    /**
     * A YEAR IS NOT RECONCILED WHILE A TENANT'S SHARE HAS NOT BEEN ACTED ON.
     *
     * The CLI has always refused this — `autoTrueUpForYear()` marks a pool reconciled only when
     * billing actually ran — and the button did not, so a pool could read "Reconciled ✓" with every
     * allocation still pending and not one tenant charged. Measured on the demo books: 36
     * allocations, 0 billed, button live.
     *
     * A METHOD, not an inline `if`, because `disabled()` is refused at dispatch on this Filament
     * version — so `callAction()` never reaches the action body and the guard inside it cannot be
     * proved through the page. Mutation said so: deleting the inline check left the refusal test
     * fully green. It is kept as the second layer for the reason the authz invariant gives (hidden-
     * implies-disabled is an upstream detail that can change in a release), and it is now provable.
     */
    public static function assertReadyToReconcile(CamExpensePool $record): void
    {
        if (($unbilled = self::unbilledCount($record)) > 0) {
            throw new DomainException(__('admin.refusals.cam_pool_has_unbilled_allocations', [
                'count' => $unbilled,
            ]));
        }
    }

    /** Allocations nobody has acted on yet. `disputed` and `closed` are decisions; `pending` is not. */
    public static function unbilledCount(CamExpensePool $record): int
    {
        return $record->allocations()->where('status', 'pending')->count();
    }

    /**
     * Same permission as the per-allocation Bill — batching an act is not a different right — plus
     * something to bill. An empty batch button on a fully-billed pool reads as a broken one.
     */
    public static function canBillAll(CamExpensePool $record): bool
    {
        return self::unbilledCount($record) > 0
            && (auth()->user()?->can('cam.bill_allocation') ?? false);
    }

    /** What the batch is about to do, in figures, for the confirmation modal. */
    private static function batchSummary(CamExpensePool $record): string
    {
        $rows = $record->allocations()->where('status', 'pending')->get(['true_up_amount', 'admin_fee_amount']);

        $recover = $rows->filter(fn ($a) => (float) $a->true_up_amount > 0.005);
        $credit = $rows->filter(fn ($a) => (float) $a->true_up_amount < -0.005);

        return __('admin.actions.bill_all_pending_confirm', [
            'count' => $rows->count(),
            'invoices' => $recover->count(),
            'recovered' => number_format((float) $recover->sum('true_up_amount'), 2),
            'credits' => $credit->count(),
            'credited' => number_format(abs((float) $credit->sum('true_up_amount')), 2),
            'fees' => number_format((float) $rows->sum('admin_fee_amount'), 2),
        ]);
    }
}
