<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\TaxCode;
use App\Models\VendorBill;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\Journalizers\Concerns\MapsExpenseCategory;
use Illuminate\Database\Eloquent\Model;

/**
 * Vendor bill (فاتورة مورد) — recognise what we owe, and what for.
 *
 *   Dr GRNI (the part paying for goods already received)
 *   Dr Expense (the rest of net, by category)
 *   Dr VAT Recoverable (input VAT)
 *   Cr Accounts Payable (total)
 *
 * The GRNI line is the second half of a purchase. A goods receipt posts Dr Inventory / Cr GRNI
 * — "we have the goods, not yet the invoice" — and this clears it: Dr GRNI / Cr AP. The expense
 * hits the P&L later, when the stock is consumed (Dr R&M / Cr Inventory).
 *
 * Without it the same money was recognised twice: buying 500 EGP of stock once left Inventory
 * +500, Expense +500, GRNI −500 and AP −500 — the P&L and the balance sheet each overstated by
 * the full value of the purchase, and 166,120 EGP of GRNI on the demo books that could never be
 * cleared because nothing linked a bill to the receipt it paid for.
 *
 * A bill with no `purchase_request_id` is unchanged: all of net is expense. That is most bills.
 *
 * Posts once the bill is past draft (approved+); drafts/cancelled are skipped.
 */
class VendorBillJournalizer implements Journalizer
{
    use MapsExpenseCategory;

    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var VendorBill $bill */
        $bill = $source;

        if (! $bill->isPostable()) {
            return null;
        }

        $assetId = $bill->asset_id;
        $vat = round((float) $bill->vat_amount, 2);
        $total = round((float) $bill->total, 2);
        // Derive the net expense from total − VAT so the entry always balances to
        // the payable, even if a stored subtotal drifts from total − vat.
        $net = round($total - $vat, 2);

        if ($total <= 0) {
            return null;
        }

        $expenseRole = $this->expenseRoleFor($bill->category, "bill {$bill->number}");

        $lines = [];

        // How much of this bill is paying for goods we have ALREADY taken into stock?
        //
        // That part must clear GRNI, not charge the expense: the receipt already debited
        // Inventory and credited GRNI ("we have the goods, not yet the invoice"), so charging
        // the expense here too would recognise the same money twice — once as an asset and once
        // in the P&L — while GRNI sat uncleared forever. Proven before this: buying 500 EGP of
        // stock once left Inventory +500, Expense +500, GRNI −500 AND AP −500.
        //
        // The expense hits the P&L later, when the stock is actually consumed
        // (Dr Repairs & Maintenance / Cr Inventory) — which is the whole point of perpetual
        // inventory.
        $goods = min($net, $this->goodsAwaitingInvoice($bill));

        if ($goods > 0) {
            $lines[] = [
                'ledger_account_id' => $this->accounts->id('inventory_grni', $assetId),
                'debit' => $goods,
                'credit' => 0,
                'asset_id' => $assetId,
            ];
        }

        // Whatever the bill covers beyond the goods is a genuine expense — the labour on the
        // same invoice, a delivery charge, or the whole bill when no purchase is linked.
        $expense = round($net - $goods, 2);

        // Guard > 0 — a pure-VAT bill (net 0), or one entirely for goods, would otherwise emit a
        // debit-0/credit-0 line that the posting engine rejects.
        if ($expense > 0) {
            $lines[] = [
                'ledger_account_id' => $this->accounts->id($expenseRole, $assetId),
                'debit' => $expense,
                'credit' => 0,
                'asset_id' => $assetId,
            ];
        }

        if ($vat > 0) {
            // The input-tax leg reads the bill's OWN `tax_code`, with `vat_recoverable` as the
            // floor for a bill that names none (legacy, or an import). Until 2026-08-19 the account
            // was hard-coded, which is why stamp and schedule tax could not be switched on: their
            // input side is an EXPENSE, not a recoverable asset — neither has a credit mechanism
            // this operator can use, so posting one here as `vat_recoverable` would have grown a
            // receivable nobody could ever collect, on the balance sheet, indefinitely. The
            // asymmetry with VAT is the whole point; see `ChartOfAccountsSeeder` 51111.
            $taxRole = ($bill->tax_code ? TaxCode::postingRoleOf((string) $bill->tax_code) : null)
                ?? 'vat_recoverable';

            $lines[] = [
                'ledger_account_id' => $this->accounts->id($taxRole, $assetId),
                'debit' => $vat,
                'credit' => 0,
                'asset_id' => $assetId,
            ];
        }

        $lines[] = [
            'ledger_account_id' => $this->accounts->id('accounts_payable', $assetId),
            'debit' => 0,
            'credit' => $total,
            'asset_id' => $assetId,
        ];

        return [
            'entry_date' => $bill->bill_date,
            'description_en' => 'Vendor bill '.$bill->number,
            'description_ar' => 'فاتورة مورد '.$bill->number,
            'asset_id' => $assetId,
            'lines' => $lines,
        ];
    }

    /**
     * The value of goods received against this bill's purchase that GRNI is still holding.
     *
     * Only RECEIVED, stockable lines: a service line never touched stock, and an unreceived line
     * has posted nothing to GRNI yet, so neither has anything to clear. Reading it from the lines
     * that actually produced a movement (`stock_movement_id`) rather than from the request's total
     * keeps this true for a partially-received purchase, and means the figure can never claim to
     * clear more than the receipt actually credited.
     */
    private function goodsAwaitingInvoice(VendorBill $bill): float
    {
        if ($bill->purchase_request_id === null) {
            return 0.0;
        }

        $request = $bill->purchaseRequest;

        if ($request === null) {
            return 0.0;
        }

        $received = round((float) $request->lines()
            ->whereNotNull('inventory_item_id')
            ->whereNotNull('stock_movement_id')
            ->sum('line_value'), 2);

        if ($received <= 0) {
            return 0.0;
        }

        // ...and share it across EVERY bill on this purchase, oldest first.
        //
        // The `min($net, …)` at the call site caps ONE bill at what the receipt credited. It does
        // not cap the AGGREGATE: this used to return the full received value to every bill, so a
        // split delivery, a deposit + balance, or simply a duplicate entry cleared GRNI twice —
        // 500 received, two 500 bills, GRNI ends at +500 (a clearing liability holding a DEBIT
        // balance) and 500 of cost vanishes from the P&L. The books still balance, so the AP/AR
        // tie-out cannot see it (gap-analysis F-101).
        //
        // FIFO by (bill_date, id) so the answer is deterministic and independent of which bill is
        // being posted or re-posted: each bill takes what is left after the ones before it, and
        // once the received value is used up later bills clear nothing and are pure expense. That
        // is also the right accounting answer — the goods were only received once.
        $remaining = $received;

        foreach ($request->bills()->postable()->orderBy('bill_date')->orderBy('id')->get() as $sibling) {
            $take = min($this->netOf($sibling), $remaining);

            if ((int) $sibling->getKey() === (int) $bill->getKey()) {
                return round(max(0.0, $take), 2);
            }

            $remaining = round($remaining - $take, 2);

            if ($remaining <= 0) {
                break;
            }
        }

        // Not among the postable siblings (e.g. this bill is draft/cancelled, so it clears
        // nothing), or the received value was exhausted before reaching it.
        return 0.0;
    }

    /** The net (ex-VAT) a bill would charge — derived the same way payload() derives it. */
    private function netOf(VendorBill $bill): float
    {
        return round(round((float) $bill->total, 2) - round((float) $bill->vat_amount, 2), 2);
    }
}
