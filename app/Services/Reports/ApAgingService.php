<?php

namespace App\Services\Reports;

use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Support\AgingBuckets;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Aged payables — whom we owe, how much, and how late (the reports audit, 2026-09-12).
 *
 * The payables side had no ageing at all: `vendor_bills` carries `due_date` and `balance`, the
 * reconcile command already tied the AP control account to the bills, and the only screen was the
 * bill register with status tabs. Nothing answered the question a mall's accountant settles every
 * week — *which suppliers do we pay first, and does what the bills say we owe agree with the books*.
 * Every accounting system prints this beside the aged receivables (Yardi's Aged Payables, SAP's
 * vendor line-item ageing, Odoo's Aged Payable); it is the mirror of `ReportService::arCollectionsByTenant()`.
 *
 * ## Aged by DUE date, and a bill with no terms is due on receipt
 *
 * Days late count from the bill's `due_date`, falling back to `bill_date` when none was recorded —
 * a supplier's invoice with no terms is payable when it arrives, and reading a null due date as
 * "never late" would hide exactly the bills nobody set terms on. The buckets are `AgingBuckets`,
 * the same boundaries the receivables age at: one company policy for how late is late, so the two
 * ageings an accountant reads side by side cannot bucket the same 45 days two ways.
 *
 * ## The set is the reconciler's set, plus the as-of cutoff
 *
 * `postable()` (drafts and cancelled bills out) and `balance > 0` — what
 * `BooksReconciliationService::glTieOut()` sums as *expected AP* — narrowed to bills dated on or
 * before the as-of day. A draft is a bill nobody has approved and it is not on the books; a
 * cancelled one left them. The cutoff is the one difference, and on today's reading it removes only
 * a bill dated in the FUTURE — which, if it has posted, shows up as a ⚠ against the control account
 * rather than being folded in, because a supplier bill dated next month is a mistyped date and the
 * ageing is where somebody will notice it.
 *
 * ## As at a date
 *
 * Bills dated on or before the as-of day, at their CURRENT balance — the same reading the
 * receivables ageing takes, and the same limit: a payment made after the as-of day has already
 * moved `balance`, so a back-dated ageing is "what is still open of what was owed then", not a
 * reconstruction of the day. The control-account tie-out is therefore offered only for TODAY.
 */
final class ApAgingService
{
    public function __construct(private BooksReconciliationService $books) {}

    /**
     * One row per supplier, worst first — how deep, then how much. A supplier 120 days late for
     * 10k is the call before one 5 days late for 100k, exactly as the collections worklist orders.
     *
     * @return Collection<int, array{vendor_id:int, vendor:?Vendor, total:float, buckets:array<string,float>, bill_count:int, oldest_days:int, last_payment_at:?string}>
     */
    public function byVendor(?CarbonImmutable $asOf = null): Collection
    {
        $asOf = $asOf ?? CarbonImmutable::now()->endOfDay();
        $bills = $this->openBillsAsOf($asOf);
        $lastPaid = $this->lastPaymentByVendor($bills->pluck('vendor_id')->unique()->values()->all());

        return $bills
            ->groupBy('vendor_id')
            ->map(function (Collection $bills, $vendorId) use ($asOf, $lastPaid): array {
                $buckets = array_fill_keys(array_keys(AgingBuckets::all()), 0.0);
                $oldest = 0;

                foreach ($bills as $bill) {
                    $days = self::daysOverdue($bill, $asOf);
                    $buckets[AgingBuckets::keyFor($days)] += (float) $bill->balance;
                    $oldest = max($oldest, $days);
                }

                return [
                    'vendor_id' => (int) $vendorId,
                    'vendor' => $bills->first()->vendor,
                    'total' => round((float) $bills->sum('balance'), 2),
                    'buckets' => array_map(fn (float $v): float => round($v, 2), $buckets),
                    'bill_count' => $bills->count(),
                    'oldest_days' => $oldest,
                    // When we last paid them anything — a supplier we have stopped paying is a
                    // different conversation from one whose bills are simply young.
                    'last_payment_at' => $lastPaid[(int) $vendorId] ?? null,
                ];
            })
            ->sortByDesc(fn (array $row) => [$row['oldest_days'], $row['total']])
            ->values();
    }

    /**
     * Open, aged-in payables as at a date — the ONE query the report, its CSV and its tie-out
     * start from, scoped to the property the operator is standing in.
     *
     * @return Collection<int, VendorBill>
     */
    public function openBillsAsOf(CarbonImmutable $asOf): Collection
    {
        return TenantScope::applyTo(VendorBill::query())
            ->postable()
            ->where('balance', '>', 0)
            ->whereDate('bill_date', '<=', $asOf)
            ->with('vendor')
            ->get();
    }

    /**
     * The last day money actually reached each supplier — one query for the page, not one per row.
     *
     * A VOIDED payment is not a payment: `VendorBill::recompute()` and the journalizer both leave it
     * out, and a cancelled cheque read as "last paid on" would say the opposite of what the column
     * is for. Scoped to the same properties the bills are — `VendorBillPayment` is property-owned
     * through its bill — so a mall pinned to one property is not shown the day another mall paid.
     * The receivables twin filters on `Payment::RECEIVED_STATUSES` for the same reason.
     *
     * @param  array<int, int>  $vendorIds
     * @return array<int, string> vendor id => `Y-m-d`
     */
    private function lastPaymentByVendor(array $vendorIds): array
    {
        if ($vendorIds === []) {
            return [];
        }

        $assetIds = TenantScope::visibleAssetIds();

        return VendorBillPayment::query()
            ->join('vendor_bills as vb', 'vb.id', '=', 'vendor_bill_payments.vendor_bill_id')
            ->whereNull('vendor_bill_payments.voided_at')
            ->whereNull('vb.deleted_at')
            ->whereIn('vb.vendor_id', $vendorIds)
            ->when($assetIds !== null, fn ($q) => $q->whereIn('vb.asset_id', $assetIds))
            ->groupBy('vb.vendor_id')
            ->selectRaw('vb.vendor_id, MAX(vendor_bill_payments.payment_date) as last_paid')
            ->pluck('last_paid', 'vendor_id')
            // Normalised to a DATE: a raw MAX() hands back whatever the driver stores (sqlite keeps
            // the time), and the CSV prints this string as it stands.
            ->map(fn ($day): string => CarbonImmutable::parse((string) $day)->toDateString())
            ->all();
    }

    /**
     * Whole days a bill is past due as at a date. Zero or negative = not yet due.
     *
     * Floored to start-of-day on both sides, as the receivables ageing does: a date column carries
     * 00:00 while the as-of carries a time, and a raw diff returns N.99… so every whole-day boundary
     * ages one bucket too far.
     */
    public static function daysOverdue(VendorBill $bill, CarbonImmutable $asOf): int
    {
        $due = $bill->due_date ?? $bill->bill_date;

        return (int) ($due?->startOfDay()->diffInDays($asOf->startOfDay(), false) ?? 0);
    }

    /**
     * The payables control account's balance for these properties — the reconciler's OWN reading of
     * where the AP money has landed, so the report cannot come to disagree with `billing:reconcile`
     * about what the ledger says. The page compares it against the total IT prints, not against the
     * reconciler's expected-AP figure: the report's set is the reconciler's set plus the as-of
     * cutoff, so a bill dated in the FUTURE that has already posted is in the ledger and not in the
     * ageing — and that is a red line the accountant should see, not a ✓ over two figures that differ.
     *
     * Null when the ledger is not configured or holds no postings: there is nothing to tie to, and
     * saying "✓" about an empty ledger would be the reassurance this project refuses to print.
     *
     * @param  array<int, int>|null  $assetIds
     */
    public function controlBalance(?array $assetIds): ?float
    {
        return $this->books->apControlBalance($assetIds);
    }
}
