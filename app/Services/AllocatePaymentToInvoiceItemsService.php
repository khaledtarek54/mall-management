<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Support\InvoiceItemSettlement;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Record which invoice LINES a payment settles (story MF-06).
 *
 * **It moves no money.** The payment's allocation to the invoice is already recorded on the
 * `invoice_payment` pivot and already counted by `Invoice::recomputeTotals()`; this only writes down
 * how that same amount splits across the lines, for the case the story exists for — a remittance
 * advice that says "here is the rent, we are still arguing about the CAM". So there is no recompute
 * to run afterwards and no GL consequence: {@see InvoiceItemSettlement} derives everything from
 * `invoices.paid_amount`, which has not changed.
 *
 * That is also why this needs no posting-date guard and no GL registry entry — it creates no journal
 * entry, because nothing about the money moved.
 */
class AllocatePaymentToInvoiceItemsService
{
    /**
     * Replace the item split for one payment against one invoice.
     *
     * Replace, not append: re-allocating after the tenant clarifies their remittance must not stack
     * a second set of rows on top of the first.
     *
     * @param  array<int, float|string|null>  $amountsByItemId  line id → amount; omit or 0 to leave a line unallocated
     */
    public function apply(Payment $payment, Invoice $invoice, array $amountsByItemId): void
    {
        if (! in_array($payment->status, Payment::RECEIVED_STATUSES, true)) {
            throw new DomainException(__('admin.errors.item_allocation_payment_not_received'));
        }

        $allocatedToInvoice = (float) DB::table('invoice_payment')
            ->where('payment_id', $payment->id)
            ->where('invoice_id', $invoice->id)
            ->value('allocated_amount');

        if ($allocatedToInvoice <= 0) {
            throw new DomainException(__('admin.errors.item_allocation_payment_not_on_invoice'));
        }

        /** @var Collection<int, InvoiceItem> $items */
        $items = $invoice->items()->get()->keyBy('id');
        $rows = [];
        $total = 0.0;

        foreach ($amountsByItemId as $itemId => $amount) {
            $amount = round((float) $amount, 2);

            if ($amount <= 0) {
                continue;
            }

            $item = $items->get((int) $itemId);

            // Not a 404 and not silence: allocating to a line on somebody else's invoice is a
            // mistake worth naming, and skipping it would report a total the operator never typed.
            if ($item === null) {
                throw new DomainException(__('admin.errors.item_allocation_foreign_item'));
            }

            if ($amount > (float) $item->total) {
                throw new DomainException(__('admin.errors.item_allocation_exceeds_line', [
                    'line' => $item->description,
                    'total' => number_format((float) $item->total, 2),
                ]));
            }

            $rows[] = [
                'invoice_item_id' => $item->id,
                'payment_id' => $payment->id,
                'allocated_amount' => $amount,
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ];
            $total = round($total + $amount, 2);
        }

        // The split cannot claim more of the payment than the payment gave this invoice, or the
        // lines would report settlement out of money that went somewhere else.
        if ($total > $allocatedToInvoice) {
            throw new DomainException(__('admin.errors.item_allocation_exceeds_payment', [
                'total' => number_format($total, 2),
                'allocated' => number_format($allocatedToInvoice, 2),
            ]));
        }

        DB::transaction(function () use ($payment, $items, $rows) {
            DB::table('invoice_item_payment')
                ->where('payment_id', $payment->id)
                ->whereIn('invoice_item_id', $items->keys())
                ->delete();

            if ($rows !== []) {
                DB::table('invoice_item_payment')->insert($rows);
            }
        });
    }

    /** Forget the split entirely and fall back to charge-type priority. */
    public function clear(Payment $payment, Invoice $invoice): void
    {
        DB::table('invoice_item_payment')
            ->where('payment_id', $payment->id)
            ->whereIn('invoice_item_id', $invoice->items()->pluck('id'))
            ->delete();
    }
}
