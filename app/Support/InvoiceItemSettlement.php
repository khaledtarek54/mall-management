<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How much of each invoice LINE is settled, and how much is still owed (story MF-06).
 *
 * **Derived, never stored — that is the whole design.** `Invoice::recomputeTotals()` remains the
 * single source of truth for what an invoice has been paid, across all four AR channels. This class
 * takes that one number and splits it across the lines. Because every figure comes out of
 * `invoices.paid_amount`, the per-item outstandings sum back to `invoices.balance`: there is no
 * second ledger to drift, and adding a fifth settlement channel later changes nothing here.
 *
 * **The precise scope of that tie-out, because this docblock first said "always" and that was too
 * strong.** It holds while the invoice is internally consistent — `total` equal to the sum of its
 * items. `total` is a header column and deleting an item does not recompute it, so a mutilated
 * invoice reports `balance` against a header figure while these numbers reflect the lines that
 * remain. That state is already broken independently of this class, and `billing:reconcile`'s first
 * check ("Invoice total = line-item subtotal + VAT") is what catches it. The guarantee is
 * conditional on that check passing, which is the honest claim.
 *
 * A stored per-item balance would have been the obvious shape and the wrong one. It would be a
 * second truth about the same money, reconciled by hand, and the first credit note applied without
 * an item breakdown would have desynchronised it silently.
 *
 * **Two ways a line gets settled.**
 *  1. **Explicitly** — the operator recorded a split against a payment (`invoice_item_payment`),
 *     because the tenant's remittance advice said what they were paying for. This is the case the
 *     story exists for: "here is the rent, we are still arguing about the CAM."
 *  2. **By priority** — everything else. Yardi applies a receipt to open charges by charge-code
 *     priority (02-yardi-money-flow.md §4), so Atriom does too, using {@see self::TYPE_PRIORITY}.
 *
 * **Known limitation: a CREDIT NOTE cannot be pointed at a line.** Only payments carry an item
 * allocation. A credit issued specifically to settle a disputed service charge is counted in
 * `paid_amount` and then distributed by priority like any other money — so it lands on rent first,
 * and the disputed line still reads as outstanding. The consequence is conservative: a late fee is
 * suppressed on an amount that has in fact been credited, so the tenant is under-charged rather
 * than over-charged. The workflow also covers it, since an operator who credits a dispute resolves
 * it. Giving `CreditNoteApplication` the same item-level allocation as `invoice_item_payment` is
 * the fix if it ever matters.
 *
 * **Deviation from Yardi, stated:** Voyager makes the order configurable per AR settings and shops
 * tune it. Atriom's is a constant. Nobody has asked to tune it, and a settings screen nothing reads
 * is worse than an explicit constant — this project has shipped that bug twice. When an operator
 * does ask, move this array into `BillingSettings` and read it through `ScheduleSetting`.
 */
class InvoiceItemSettlement
{
    /**
     * The order a payment settles lines in when nobody said otherwise.
     *
     * **Rent first, late fees last.** Rent is the obligation a landlord most needs secured, and
     * putting fees last means a partial payment is never eaten by a penalty — so a disputed late fee
     * stays visible in aging as a late fee instead of quietly consuming the rent that was paid.
     * Types absent from this list settle after everything listed, in item order.
     */
    public const TYPE_PRIORITY = [
        'base_rent',
        'service_charge',
        'marketing',
        'utility',
        'parking',
        'percentage_rent',
        'other',
        // Penalties settle LAST, so a part payment is never eaten by one — a disputed fee stays
        // visible in aging as a fee instead of quietly consuming the rent that was paid.
        'nsf_fee',
        'late_fee',
    ];

    /**
     * Per-line settlement for one invoice.
     *
     * @return Collection<int, array{item: InvoiceItem, type: string, total: float, settled: float, outstanding: float, explicit: float}>
     */
    public static function for(Invoice $invoice): Collection
    {
        /** @var Collection<int, InvoiceItem> $items */
        $items = $invoice->items()->orderBy('id')->get();

        return self::split($invoice, $items, self::explicitAllocations($invoice));
    }

    /**
     * The same split for many invoices at once, loading the allocations in ONE query.
     *
     * The report walks every open invoice in the property; calling {@see self::for} in that loop is
     * a query per invoice for rows that almost never exist.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return array<int, Collection<int, array{item: InvoiceItem, type: string, total: float, settled: float, outstanding: float, explicit: float}>> invoice id → its lines
     */
    public static function forMany(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }

        $items = InvoiceItem::whereIn('invoice_id', $invoices->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('invoice_id');

        $explicit = self::explicitAllocationsFor($invoices->pluck('id')->all());

        $out = [];

        foreach ($invoices as $invoice) {
            /** @var Collection<int, InvoiceItem> $own */
            $own = $items->get($invoice->id) ?? collect();

            $out[$invoice->id] = self::split($invoice, $own, $explicit);
        }

        return $out;
    }

    /**
     * @param  Collection<int, InvoiceItem>  $items
     * @param  array<int, float>  $explicit
     * @return Collection<int, array{item: InvoiceItem, type: string, total: float, settled: float, outstanding: float, explicit: float}>
     */
    private static function split(Invoice $invoice, Collection $items, array $explicit): Collection
    {
        if ($items->isEmpty()) {
            return collect();
        }

        // The one truth. Everything below only decides how THIS number is spread.
        $pool = (float) $invoice->paid_amount;

        // A cancelled invoice claims no AR at all — `recomputeTotals()` forces its balance to 0, so
        // its lines must show nothing outstanding or the two would disagree.
        if ($invoice->status === 'cancelled') {
            return $items->map(fn (InvoiceItem $item) => [
                'item' => $item,
                'type' => $item->type,
                'total' => round((float) $item->total, 2),
                'settled' => round((float) $item->total, 2),
                'outstanding' => 0.0,
                'explicit' => round($explicit[$item->id] ?? 0.0, 2),
            ]);
        }

        $settled = [];

        // ── Pass 1: what the operator actually recorded ────────────────────────────────────────
        // Capped at the line's own total, and at the pool: a payment that was later voided or
        // refitted leaves allocation rows behind that now claim more than the invoice has received,
        // and honouring them would report a line as paid out of money that is no longer there.
        $explicitTotal = array_sum($explicit);
        $scale = ($explicitTotal > $pool && $explicitTotal > 0) ? $pool / $explicitTotal : 1.0;

        foreach ($items as $item) {
            $amount = min(
                ($explicit[$item->id] ?? 0.0) * $scale,
                (float) $item->total,
                max(0.0, $pool),
            );

            $settled[$item->id] = round($amount, 2);
            $pool = round($pool - $amount, 2);
        }

        // ── Pass 2: the rest, by charge-type priority ──────────────────────────────────────────
        foreach (self::inPriorityOrder($items) as $item) {
            if ($pool <= 0) {
                break;
            }

            $room = round((float) $item->total - $settled[$item->id], 2);

            if ($room <= 0) {
                continue;
            }

            $take = min($room, $pool);
            $settled[$item->id] = round($settled[$item->id] + $take, 2);
            $pool = round($pool - $take, 2);
        }

        return $items->map(fn (InvoiceItem $item) => [
            'item' => $item,
            'type' => $item->type,
            'total' => round((float) $item->total, 2),
            'settled' => $settled[$item->id],
            'outstanding' => round((float) $item->total - $settled[$item->id], 2),
            'explicit' => round($explicit[$item->id] ?? 0.0, 2),
        ]);
    }

    /**
     * Outstanding by charge type — what RR-03's aging is built on.
     *
     * @return array<string, float>
     */
    public static function outstandingByType(Invoice $invoice): array
    {
        return self::for($invoice)
            ->groupBy('type')
            ->map(fn (Collection $rows) => round((float) $rows->sum('outstanding'), 2))
            ->filter(fn (float $amount) => $amount > 0)
            ->all();
    }

    /**
     * Explicit item allocations, counting only payments that are actually money.
     *
     * The same `RECEIVED_STATUSES` filter `recomputeTotals()` uses — a voided or pending payment's
     * rows must not settle a line the invoice does not consider paid.
     *
     * @return array<int, float>
     */
    private static function explicitAllocations(Invoice $invoice): array
    {
        return self::explicitAllocationsFor([$invoice->id]);
    }

    /**
     * @param  array<int, int>  $invoiceIds
     * @return array<int, float>
     */
    private static function explicitAllocationsFor(array $invoiceIds): array
    {
        return DB::table('invoice_item_payment')
            ->join('invoice_items', 'invoice_items.id', '=', 'invoice_item_payment.invoice_item_id')
            ->join('payments', 'payments.id', '=', 'invoice_item_payment.payment_id')
            ->whereIn('invoice_items.invoice_id', $invoiceIds)
            ->whereIn('payments.status', Payment::RECEIVED_STATUSES)
            ->groupBy('invoice_item_payment.invoice_item_id')
            // Aliased, not `pluck(DB::raw(...))`: pluck reads the value off the result row by
            // property name, and a raw expression is not one.
            ->selectRaw('invoice_item_payment.invoice_item_id as item_id, sum(invoice_item_payment.allocated_amount) as allocated')
            ->pluck('allocated', 'item_id')
            ->map(fn ($amount) => (float) $amount)
            ->all();
    }

    /**
     * @param  Collection<int, InvoiceItem>  $items
     * @return Collection<int, InvoiceItem>
     */
    private static function inPriorityOrder(Collection $items): Collection
    {
        return $items->sortBy([
            fn (InvoiceItem $a, InvoiceItem $b) => self::rank($a) <=> self::rank($b),
            fn (InvoiceItem $a, InvoiceItem $b) => $a->id <=> $b->id,
        ])->values();
    }

    private static function rank(InvoiceItem $item): int
    {
        $rank = array_search($item->type, self::TYPE_PRIORITY, true);

        return $rank === false ? count(self::TYPE_PRIORITY) : $rank;
    }
}
