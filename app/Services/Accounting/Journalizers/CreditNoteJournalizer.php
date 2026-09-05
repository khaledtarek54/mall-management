<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\CreditNote;
use App\Models\TaxCode;
use App\Services\Accounting\AccountResolver;
use App\Support\DepositBilling;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Credit note (إشعار خصم):
 *   Dr Sales Returns & Allowances (subtotal)  +  Dr the tax the supply carried (reversed)
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

        // **ONE PREDICATE, DERIVED.** This was the hand-rolled allowlist `['issued', 'applied']`,
        // which is the complement of `CreditNote::NOT_ON_THE_BOOKS` by COINCIDENCE rather than by
        // derivation — so a fifth status would be counted by every documents-side read (they
        // EXCLUDE the two that are off the books) and skipped by the GL (it ALLOWED two), silently
        // and in the direction where the books and the documents disagree.
        if (! $note->isOnTheBooks()) {
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

        // ── A CREDITED DEPOSIT LINE RELIEVES THE OBLIGATION, NOT REVENUE (SW-238) ─────────────
        // The write-off twin of SW-210, through the credit door: a `security_deposit` line credited
        // `deposits_held` — a LIABILITY — at issue, so debiting `sales_returns` for it reversed
        // revenue never recognised and left the obligation standing. A fully credited 100,000
        // deposit left the GL saying 100,000 held where the truth is 0, and `deposits_tie_out` red
        // with no write-off anywhere near it.
        //
        // `deposit_amount` is FROZEN on the note, maintained from its own lines while they are
        // written — never re-derived here, so a posted entry cannot drift (the SW-236 hazard SW-210
        // was reworked for). Legacy rows carry 0.00 and post exactly what they always posted:
        // PROSPECTIVE, because SW-216's backfill typed historical lines and keying on the type
        // would restate closed periods. Clamped so a hand-edited column can never unbalance the
        // entry, and the role is resolved as the issue resolved it — a reversal never re-classifies
        // what it reverses.
        $deposit = min(max(round((float) $note->deposit_amount, 2), 0.0), $netReturn);
        $return = round($netReturn - $deposit, 2);

        // Guard > 0 on each — a zero line is rejected by the posting engine, and an all-deposit
        // note has no revenue half at all.
        if ($return > 0) {
            $lines[] = [
                'ledger_account_id' => $this->accounts->id('sales_returns', $assetId),
                'debit' => $return,
                'credit' => 0,
                'tenant_id' => $note->tenant_id,
            ];
        }

        if ($deposit > 0) {
            $lines[] = [
                'ledger_account_id' => $this->accounts->id(DepositBilling::depositPostingRole(), $assetId),
                'debit' => $deposit,
                'credit' => 0,
                'tenant_id' => $note->tenant_id,
            ];
        }

        // The tax is reversed at the SUPPLY'S OWN posting role, exactly as `InvoiceJournalizer`
        // charges it — grouped by each line's `tax_code`, with `vat_payable` as the floor for a line
        // that names none.
        //
        // This journalizer hard-coded `vat_payable` until 2026-08-24, and the day that becomes wrong
        // is not a deploy: pointing a charge code at a stamp or schedule code is a ROW the accountant
        // writes (the open C-TAX question), reaching every lease already on the books. From that day
        // the invoice would credit `stamp_tax_payable` and its credit note debit `vat_payable` —
        // stamp liability permanently overstated, VAT understated, and the VAT return's `ties_out`
        // control false in every affected period, with both entries balanced throughout.
        //
        // A reversal never re-classifies the tax it reverses.
        foreach ($this->taxByRole($note, $vat) as $role => $amount) {
            $amount = round((float) $amount, 2);

            if ($amount <= 0) {
                continue;
            }

            $lines[] = [
                'ledger_account_id' => $this->accounts->id($role, $assetId),
                'debit' => $amount,
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
            // The narrative is a KEY resolved at READ time (EG-36); the prose above stays as
            // the snapshot and the floor for anything that does not go through the resolver.
            'description_key' => 'credit_note.posted',
            'description_data' => ['number' => $note->number],
            'asset_id' => $assetId,
            'lines' => $lines,
        ];
    }

    /**
     * The note's tax, split by the posting role each line's own tax code carries.
     *
     * Two things make the HEADER figure the authority on the total rather than the lines: the note's
     * `vat_amount` is what the AR credit was derived from above (`total − vat`), so a lines-derived
     * total that disagreed by a piaster would emit an unbalanced entry; and a legacy or header-only
     * note carries no lines to classify at all. So the lines decide the SPLIT and the header decides
     * the SIZE — any rounding difference lands on the largest role, which is the same role a
     * single-tax note would have used anyway.
     *
     * @return array<string, float>
     */
    private function taxByRole(CreditNote $note, float $vat): array
    {
        if ($vat <= 0) {
            return [];
        }

        $note->loadMissing('items');

        $byRole = [];

        foreach ($note->items as $item) {
            $lineTax = round((float) ($item->vat_amount ?? 0), 2);

            if ($lineTax == 0.0) {
                continue;
            }

            // `vat_payable` is the FLOOR, not a guess — a line with no `tax_code` predates the
            // catalogue or came from a service that does not classify, and VAT is what it was.
            // Identical rule and identical wording to InvoiceJournalizer, deliberately.
            $role = ($item->tax_code ? TaxCode::postingRoleOf((string) $item->tax_code) : null)
                ?? 'vat_payable';

            $byRole[$role] = ($byRole[$role] ?? 0) + $lineTax;
        }

        if ($byRole === []) {
            return ['vat_payable' => $vat];
        }

        // Reconcile the split to the header, so the entry balances to the receivable being reversed.
        $split = round(array_sum($byRole), 2);

        if ($split != $vat) {
            arsort($byRole);
            $largest = array_key_first($byRole);
            $byRole[$largest] = round($byRole[$largest] + ($vat - $split), 2);
        }

        return $byRole;
    }
}
