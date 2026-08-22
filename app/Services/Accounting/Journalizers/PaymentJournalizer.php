<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\Payment;
use App\Services\Accounting\AccountResolver;
use App\Support\MoneyAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Capture payment (تحصيل دفعة):
 *   Dr Cash / Bank (amount received)
 *   Cr Accounts Receivable (amount allocated to invoices)
 *   Cr Unearned Revenue (any unallocated remainder — a customer advance)
 *
 * Only `captured` payments post; everything else has no GL effect.
 */
class PaymentJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var Payment $payment */
        $payment = $source;

        if (! $payment->isReceived()) {
            return null;
        }

        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $payment->loadMissing('invoices');

        // A payment can pay invoices across DIFFERENT properties (the form scopes by
        // tenant, not asset). Reduce each property's receivables on its own asset so
        // per-property books stay correct. Bucket the allocations by invoice asset.
        $allocByAsset = [];
        foreach ($payment->invoices as $invoice) {
            $alloc = round((float) $invoice->pivot->allocated_amount, 2);
            if ($alloc <= 0) {
                continue;
            }
            // The invoice's own column. InvoiceJournalizer already debits THIS property's AR at
            // issue, so deriving the credit through the lease chain sent an owner invoice's payment
            // to portfolio-level AR — a permanent per-property drift that a portfolio-wide tie-out
            // cannot see, because it nets to zero across the malls.
            $assetId = $invoice->asset_id; // null = no-asset bucket
            $key = $assetId ?? 0;
            $allocByAsset[$key] = round(($allocByAsset[$key] ?? 0) + $alloc, 2);
        }

        // Entry-level books dimension: the single asset if all allocations share one,
        // else null (a genuinely cross-property / consolidated receipt).
        $distinctAssets = array_values(array_filter(array_keys($allocByAsset), fn ($k) => $k !== 0));

        $entryAsset = match (true) {
            count($distinctAssets) === 1 => $distinctAssets[0],
            // NOTHING to derive from — a receipt with no allocations at all. Reachable: clearing a
            // post-dated cheque recorded with no invoice (the form requires a tenant, not an
            // invoice) produced Dr bank / Cr unearned revenue with a NULL property, so the receipt
            // showed on every mall and reached no owner statement. The property was on the cheque
            // the whole time. See `Payment::originatingAssetId()`.
            $allocByAsset === [] => $payment->originatingAssetId(),
            // Allocated, and across more than one property — or across invoices that themselves
            // carry none. Both stay null deliberately: the first is a real consolidated receipt,
            // and the second is an invoice-level defect that `atriom:audit-property-dimension`
            // reports on the invoices. Filling it in here would hide the one and mis-file the other.
            default => null,
        };

        // The RAIL says where its money lands. Null role = the floor, which is verbatim the
        // ternary this replaced (`cash` for cash, `bank` for everything else) — so an unseeded
        // catalogue posts exactly as before. What the floor is wrong about is the whole point: a
        // card capture debits the bank on CAPTURE day while the money lands T+1/T+2, so the book
        // line and the bank line carry different dates and every reconciliation shows them
        // unmatched. Pointing the rail at a clearing account fixes that without a deploy.
        $cashAccountId = MoneyAccount::for($payment->bank_account_id, $payment->method, $entryAsset, $this->accounts);

        $lines = [[
            'ledger_account_id' => $cashAccountId,
            'debit' => $amount,
            'credit' => 0,
            'asset_id' => $entryAsset,
        ]];

        // Credit each property's receivables, capped to the cash actually received
        // (guards the over-allocation edge so the entry always balances to `amount`).
        $remaining = $amount;
        foreach ($allocByAsset as $key => $alloc) {
            if ($remaining <= 0) {
                break;
            }
            $credit = round(min($alloc, $remaining), 2);
            if ($credit <= 0) {
                continue;
            }
            $assetId = $key === 0 ? null : $key;
            $lines[] = [
                'ledger_account_id' => $this->accounts->id('accounts_receivable', $assetId),
                'debit' => 0,
                'credit' => $credit,
                'asset_id' => $assetId,
                'tenant_id' => $payment->tenant_id,
            ];
            $remaining = round($remaining - $credit, 2);
        }

        // Any unallocated remainder is a customer advance (unearned revenue).
        if ($remaining > 0) {
            $lines[] = [
                'ledger_account_id' => $this->accounts->id('unearned_revenue', $entryAsset),
                'debit' => 0,
                'credit' => $remaining,
                'asset_id' => $entryAsset,
                'tenant_id' => $payment->tenant_id,
            ];
        }

        return [
            'entry_date' => $payment->payment_date,
            'description_en' => 'Payment '.$payment->reference,
            'description_ar' => 'دفعة '.$payment->reference,
            'asset_id' => $entryAsset,
            'lines' => $lines,
        ];
    }
}
