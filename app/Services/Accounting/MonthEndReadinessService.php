<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\VendorBill;
use App\Services\MonthlyBillingService;
use App\Services\Reconciliation\BooksReconciliationService;
use Carbon\CarbonImmutable;
use DomainException;
use Throwable;

/**
 * Is this month ready to close?
 *
 * Closing a month has always been tribal knowledge here: bill everything, chase the sales
 * declarations, get the receipts in, post the AP, make sure the ledger is synced, check the books
 * tie out, then close. Every one of those facts was already computable and none of them was on a
 * screen, so "did we do it all" was answered from memory once a month by whoever remembered.
 *
 * This assembles them into one ordered checklist — Yardi's Month-End dashboard (UX-04,
 * docs/benchmarks/yardi/08-yardi-ui-ux.md), in the order of
 * docs/benchmarks/yardi/02-yardi-money-flow.md §9.
 *
 * **Read-only. It computes nothing new and it changes nothing** — every number comes from the
 * service that already owns that decision (`MonthlyBillingService::previewForPeriod()`,
 * `Lease::missingSalesDeclarationsFor()`, `PeriodService::assertPeriodsReconciled()`,
 * `BooksReconciliationService::run()`). That matters more than it sounds: a checklist that
 * re-implements "is billing done" is a checklist that can say done when it isn't.
 *
 * The `ledger_in_sync` step is the real close gate — `PeriodService::closePeriod()` refuses on
 * exactly the same assertion, so a green checklist means the close will actually go through
 * rather than throwing at the last click.
 */
class MonthEndReadinessService
{
    /** A step needing attention, but not one that blocks the close. */
    public const ATTENTION = 'attention';

    /** Nothing outstanding. */
    public const OK = 'ok';

    /** The close itself will be refused until this clears. */
    public const BLOCKED = 'blocked';

    /** Already done — the period is closed. */
    public const DONE = 'done';

    public function __construct(
        private MonthlyBillingService $billing,
        private PeriodService $periods,
        private BooksReconciliationService $books,
    ) {}

    /**
     * @return array{
     *     period: string,
     *     period_label: string,
     *     accounting_period: ?AccountingPeriod,
     *     closed: bool,
     *     ready: bool,
     *     blocking: int,
     *     outstanding: int,
     *     steps: array<int, array{key:string, status:string, count:int, detail:?string}>
     * }
     */
    public function for(CarbonImmutable $month, ?int $assetId = null): array
    {
        $periodStart = $month->startOfMonth();
        $periodEnd = $periodStart->endOfMonth();
        $accountingPeriod = AccountingPeriod::forDate($periodStart);
        $closed = $accountingPeriod !== null && ! $accountingPeriod->isOpen();

        $steps = [
            $this->billingStep($periodStart, $assetId),
            $this->salesDeclarationStep($periodStart, $periodEnd, $assetId),
            $this->paymentsStep($periodStart, $periodEnd, $assetId),
            $this->vendorBillsStep($periodStart, $periodEnd, $assetId),
            $this->ledgerSyncStep($accountingPeriod),
            $this->booksTieOutStep($periodStart),
            $this->periodStep($accountingPeriod, $closed),
        ];

        return [
            'period' => $periodStart->format('Y-m'),
            'period_label' => $periodStart->locale(app()->getLocale())->isoFormat('MMMM YYYY'),
            'accounting_period' => $accountingPeriod,
            'closed' => $closed,
            'ready' => collect($steps)->every(fn (array $s) => in_array($s['status'], [self::OK, self::DONE], true)),
            'blocking' => collect($steps)->where('status', self::BLOCKED)->count(),
            'outstanding' => collect($steps)->where('status', self::ATTENTION)->count(),
            'steps' => $steps,
        ];
    }

    /** 1. Every billable lease has its invoice. Straight from the preview the operator posts from. */
    private function billingStep(CarbonImmutable $periodStart, ?int $assetId): array
    {
        $pending = $this->billing->previewForPeriod($periodStart, $assetId)['totals']['will_bill'];

        return $this->step('billing_posted', $pending);
    }

    /** 2. Percentage-rent tenants have declared. Undeclared sales = unbillable overage. */
    private function salesDeclarationStep(CarbonImmutable $periodStart, CarbonImmutable $periodEnd, ?int $assetId): array
    {
        return $this->step(
            'sales_declared',
            Lease::missingSalesDeclarationsFor($periodStart, $periodEnd, $assetId)->count(),
        );
    }

    /**
     * 3. No payment left mid-flight.
     *
     * `initiated`/`authorized` money is money the gateway took and the books have not: it settles
     * nothing, so an invoice still reads unpaid. Left over a close, it lands in the wrong month.
     */
    private function paymentsStep(CarbonImmutable $periodStart, CarbonImmutable $periodEnd, ?int $assetId): array
    {
        $count = Payment::query()
            ->whereIn('status', ['initiated', 'authorized'])
            ->whereBetween('payment_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->when($assetId, fn ($q) => $q->whereHas(
                'invoices.lease.unit',
                fn ($u) => $u->where('asset_id', $assetId),
            ))
            ->count();

        return $this->step('payments_settled', $count);
    }

    /** 4. Draft vendor bills for the month — an unposted expense is an understated month. */
    private function vendorBillsStep(CarbonImmutable $periodStart, CarbonImmutable $periodEnd, ?int $assetId): array
    {
        $count = VendorBill::query()
            ->where('status', 'draft')
            ->whereBetween('bill_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->when($assetId, fn ($q) => $q->where('asset_id', $assetId))
            ->count();

        return $this->step('vendor_bills_posted', $count);
    }

    /**
     * 5. THE CLOSE GATE. Documents whose posted entry is out of step with their current state.
     *
     * Deliberately the same assertion `PeriodService::closePeriod()` runs, caught rather than
     * re-implemented — so a green row here means the close will actually succeed, and a red one
     * carries the service's own message rather than a paraphrase of it.
     */
    private function ledgerSyncStep(?AccountingPeriod $period): array
    {
        if ($period === null) {
            // No accounting period covers this month. Not an error — a MISSING period is allowed
            // (only a CLOSED one refuses postings) — but there is nothing to gate on either.
            return $this->step('ledger_in_sync', 0);
        }

        try {
            $this->periods->assertPeriodsReconciled([$period->id]);
        } catch (DomainException $e) {
            return $this->step('ledger_in_sync', 1, self::BLOCKED, $e->getMessage());
        }

        return $this->step('ledger_in_sync', 0);
    }

    /** 6. The AR books re-derive from source. Failing checks, not failing rows. */
    private function booksTieOutStep(CarbonImmutable $periodStart): array
    {
        try {
            $result = $this->books->run($periodStart->format('Y-m'));
        } catch (Throwable $e) {
            // The audit is diagnostic; if it cannot run, say so rather than reporting a clean
            // month it never actually checked.
            return $this->step('books_tie_out', 1, self::ATTENTION, $e->getMessage());
        }

        // A check counts as passed ONLY if it says so. Defaulting a missing/renamed key to "passed"
        // would make this row green for the wrong reason — the exact failure this whole screen
        // exists to prevent — so an unreadable result is treated as a failure, loudly.
        $failed = collect($result['checks'])->reject(fn (array $c) => ($c['passed'] ?? false) === true);

        return $this->step(
            'books_tie_out',
            $failed->count(),
            $failed->isEmpty() ? self::OK : self::BLOCKED,
            $failed->isEmpty() ? null : $failed->pluck('label')->implode(' · '),
        );
    }

    /** 7. The period itself. */
    private function periodStep(?AccountingPeriod $period, bool $closed): array
    {
        if ($period === null) {
            return $this->step('period_closed', 1, self::ATTENTION, __('admin.month_end.no_period'));
        }

        return $this->step('period_closed', $closed ? 0 : 1, $closed ? self::DONE : self::ATTENTION);
    }

    /** @return array{key:string, status:string, count:int, detail:?string} */
    private function step(string $key, int $count, ?string $status = null, ?string $detail = null): array
    {
        return [
            'key' => $key,
            'status' => $status ?? ($count === 0 ? self::OK : self::ATTENTION),
            'count' => $count,
            'detail' => $detail,
        ];
    }
}
