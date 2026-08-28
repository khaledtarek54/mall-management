<?php

namespace App\Support;

use App\Models\Lease;
use Carbon\CarbonImmutable;

/**
 * **Why nothing was billed, in the operator's language — one wording, every surface.**
 *
 * `MonthlyBillingService::generateForLease()` answers a refusal as a machine code — `fit_out`,
 * `off_cycle`, `lease_not_billable` — and says nothing about how to put it right. Two screens raise
 * an invoice by hand and each turned that code into words its own way:
 *
 *   - the lease's **Generate Invoice** action carried a seven-branch `if` ladder, ~100 lines, with a
 *     title and an explanation per code (and a three-way reading of `lease_not_billable`: wrong
 *     status vs. not yet commenced vs. term ended);
 *   - the **Billing forecast** tab's per-period button rendered
 *     `__('admin.billing_preview.reason.'.$reason)` and nothing else.
 *
 * That second one is how this class came to exist. The `billing_preview.reason.*` group is the
 * SHORT vocabulary for a table cell — an outcome column, three or four words — and it covered only
 * the codes the PREVIEW can show. The forecast's button routes the codes `generateForLease()` adds
 * on top of a plan (`lease_not_billable`, `run_in_progress`, `exception`), which were in neither
 * catalogue, so an operator billing an inactive lease read the literal string
 * `admin.billing_preview.reason.lease_not_billable` under the heading *"Nothing was billed"*.
 *
 * A raw key is the visible half. The invisible half is that the same refusal was a paragraph of
 * advice on one screen and three or four words on the other — and only one of them was ever
 * updated when the vocabulary grew. **Name the wording once so the two cannot drift** is the same
 * rule this codebase applies to an action's `visible()`/`action()` predicate pair.
 *
 * ## What each caller still owns
 *
 * The DECISION is not here. Both screens gate on `BillingWindow::allows()` and on
 * `leases.generate_invoice` before they ever call the service; this class is handed a result and
 * turns it into words. It reads nothing, writes nothing, and refuses nothing.
 *
 * ## Two things that are deliberate
 *
 * **The month is formatted through the reader's locale**, not `format('F Y')`. Every one of the
 * seven branches interpolated an English month name into a sentence that is otherwise Arabic —
 * «لا يمكن إصدار فاتورة لهذا العقد عن August 2026» — because `F` is Carbon's non-localised month.
 * The lease page's own billing-window refusal three lines above the ladder had it right the whole
 * time (`->locale(app()->getLocale())->isoFormat('MMMM YYYY')`), which is what one wording in two
 * places buys you: the fix lands once, on both screens.
 *
 * **`exception` is the only DANGER**. Everything else is a refusal — the lease is in fit-out, the
 * month is off-cycle, someone billed it already — and a refusal painted red reads as a fault in the
 * system rather than a fact about the lease. `MonthlyBillingService` already draws that line by
 * answering `status => 'skipped'` for a refusal and `'failed'` for a throw.
 */
final class BillingRefusal
{
    /**
     * The title, body and severity for a billing result that produced no invoice.
     *
     * @param  array{status?: string, reason?: string|null}  $result  what `generateForLease()` returned
     * @return array{title: string, body: string, danger: bool}
     */
    public static function explain(Lease $lease, CarbonImmutable $period, array $result): array
    {
        $month = $period->locale(app()->getLocale())->isoFormat('MMMM YYYY');
        $reason = ($result['status'] ?? null) === 'skipped' ? ($result['reason'] ?? null) : null;

        return match ($reason) {
            'lease_not_billable' => [
                'title' => __('admin.actions.not_billable_title', ['period' => $month]),
                'body' => self::whyNotBillable($lease, $period, $month),
                'danger' => false,
            ],
            'already_billed' => [
                'title' => __('admin.actions.already_billed_title'),
                'body' => __('admin.actions.already_billed_body', ['period' => $month]),
                'danger' => false,
            ],
            'no_applicable_charges' => [
                'title' => __('admin.actions.no_charges_title'),
                'body' => __('admin.actions.no_charges_body'),
                'danger' => false,
            ],
            'fit_out' => [
                'title' => __('admin.actions.fit_out_title'),
                'body' => __('admin.actions.fit_out_body', ['period' => $month]),
                'danger' => false,
            ],
            'off_cycle' => [
                'title' => __('admin.actions.off_cycle_title'),
                'body' => __('admin.actions.off_cycle_body', [
                    'period' => $month,
                    'frequency' => __('admin.billing_frequency.'.$lease->billing_frequency),
                ]),
                'danger' => false,
            ],
            // Reachable only from a PLAN — the lease passed `isBillableForPeriod()` for the period
            // as a whole, and then no month of the cycle fell inside its term. Distinct from
            // `lease_not_billable`'s expired branch, which is the whole period being past the end.
            'lease_ended' => [
                'title' => __('admin.actions.lease_ended_title'),
                'body' => __('admin.actions.lease_ended_body', [
                    'period' => $month,
                    'date' => $lease->expiry_date?->format('d/m/Y') ?? '—',
                ]),
                'danger' => false,
            ],
            'run_in_progress' => [
                'title' => __('admin.actions.run_in_progress_title'),
                'body' => __('admin.actions.run_in_progress_body'),
                'danger' => false,
            ],
            // `exception`, and anything a future branch of the service returns before it is worded
            // here. The fallback is the failure wording rather than a guess, because an unknown
            // code is exactly the case where telling the operator what to do next would be made up.
            default => [
                'title' => __('admin.actions.generation_failed'),
                'body' => __('admin.actions.generation_failed_body'),
                'danger' => true,
            ],
        };
    }

    /**
     * Which of the three `isBillableForPeriod()` refusals this is.
     *
     * "This lease cannot be billed" is useless on its own: the operator needs to know whether to
     * activate it, wait for commencement, or stop billing a lease that has ended. The order matters
     * — a terminated lease that also expired is a status problem first, because activating it is
     * the wrong instruction for a lease nobody meant to bill.
     */
    private static function whyNotBillable(Lease $lease, CarbonImmutable $period, string $month): string
    {
        if ($lease->status !== 'active') {
            return __('admin.actions.not_billable_status', [
                'status' => Translate::orHumanized('admin.statuses.lease.'.$lease->status, (string) $lease->status),
            ]);
        }

        if ($lease->expiry_date && $period->greaterThan(CarbonImmutable::instance($lease->expiry_date)->endOfMonth())) {
            return __('admin.actions.not_billable_expired', [
                'date' => $lease->expiry_date->format('d/m/Y'),
                'period' => $month,
            ]);
        }

        return __('admin.actions.not_billable_not_started', [
            'date' => $lease->commencement_date?->format('d/m/Y') ?? '—',
        ]);
    }
}
