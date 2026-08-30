<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Models\CamExpensePool;
use App\Services\ApplyCamEstimateService;
use App\Services\CamReconciliationService;
use App\Services\SyncCamPoolFromLedgerService;
use App\Support\RowActionPolicy;
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
            Action::make('markReconciled')
                ->label(__('admin.actions.mark_reconciled'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (CamExpensePool $record) => self::canMarkReconciled($record))
                ->action(function (CamExpensePool $record): void {
                    abort_unless(self::canMarkReconciled($record), 403);
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
}
