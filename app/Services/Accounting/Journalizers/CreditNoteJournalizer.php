<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\CreditNote;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Credit note (إشعار خصم):
 *   Dr Sales Returns & Allowances (subtotal)  +  Dr VAT Payable (VAT, reversed)
 *   Cr Accounts Receivable (total)
 *
 * Posts once the note is issued/applied; drafts and voided notes are skipped.
 */
class CreditNoteJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var CreditNote $note */
        $note = $source;

        if (! in_array($note->status, ['issued', 'applied'], true)) {
            return null;
        }

        // The note's own column. It used to walk `lease -> unit -> asset`, which answers NULL for a
        // note against a unit-OWNER invoice — the note posted with no property dimension, balanced
        // and tied out and invisible to that mall's P&L.
        $assetId = $note->asset_id;

        $vat = round((float) $note->vat_amount, 2);
        $total = round((float) $note->total, 2);
        // Derive the net (ex-VAT) return from total − VAT so the entry always
        // balances to the receivable being reversed, even if the stored subtotal
        // drifts from total − vat (no model invariant enforces subtotal+vat=total).
        $netReturn = round($total - $vat, 2);

        if ($total <= 0) {
            return null;
        }

        // Malformed data (VAT exceeds total → negative net). Unlike VendorBill/Expense,
        // CreditNote has no total = net + VAT model invariant (it's item-derived), so
        // skip + flag rather than emit an unbalanced entry.
        if ($netReturn < 0) {
            Log::warning(
                "CreditNoteJournalizer: note {$note->number} has VAT ({$vat}) exceeding total ({$total}); skipping ledger post."
            );

            return null;
        }

        $lines = [];
        // Guard net > 0 — a pure-VAT credit note (net 0) would otherwise emit a
        // debit-0/credit-0 line that the posting engine rejects.
        if ($netReturn > 0) {
            $lines[] = [
                'ledger_account_id' => $this->accounts->id('sales_returns', $assetId),
                'debit' => $netReturn,
                'credit' => 0,
                'tenant_id' => $note->tenant_id,
            ];
        }

        if ($vat > 0) {
            $lines[] = [
                'ledger_account_id' => $this->accounts->id('vat_payable', $assetId),
                'debit' => $vat,
                'credit' => 0,
            ];
        }

        $lines[] = [
            'ledger_account_id' => $this->accounts->id('accounts_receivable', $assetId),
            'debit' => 0,
            'credit' => $total,
            'tenant_id' => $note->tenant_id,
        ];

        return [
            'entry_date' => $note->issue_date,
            'description_en' => 'Credit note '.$note->number,
            'description_ar' => 'إشعار خصم '.$note->number,
            'asset_id' => $assetId,
            'lines' => $lines,
        ];
    }
}
