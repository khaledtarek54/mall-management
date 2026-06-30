<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\CreditNote;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

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

        $note->loadMissing('lease.unit');
        $assetId = $note->lease?->unit?->asset_id;

        $vat = round((float) $note->vat_amount, 2);
        $total = round((float) $note->total, 2);
        // Derive the net (ex-VAT) return from total − VAT so the entry always
        // balances to the receivable being reversed, even if the stored subtotal
        // drifts from total − vat (no model invariant enforces subtotal+vat=total).
        $netReturn = round($total - $vat, 2);

        if ($total <= 0) {
            return null;
        }

        $lines = [[
            'ledger_account_id' => $this->accounts->id('sales_returns', $assetId),
            'debit' => $netReturn,
            'credit' => 0,
            'tenant_id' => $note->tenant_id,
        ]];

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
