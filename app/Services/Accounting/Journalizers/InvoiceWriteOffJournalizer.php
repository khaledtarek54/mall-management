<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\InvoiceWriteOff;
use App\Services\Accounting\AccountResolver;
use App\Support\DepositBilling;
use Illuminate\Database\Eloquent\Model;

/**
 * Write off a bad debt (إعدام دين):
 *   Dr Bad Debt Expense   (the amount accepted as uncollectible)
 *   Dr Deposits Held      (the part that reaches a security-deposit line — SW-210)
 *   Cr Accounts Receivable
 *
 * **A write-off reverses whatever the line originally CREDITED.** For a revenue line that is
 * bad-debt expense, which is what a write-off means. But a `security_deposit` line credited
 * `deposits_held` — a LIABILITY (`InvoiceJournalizer::REVENUE_ROLE`) — so no revenue was ever
 * recognised against it, and debiting bad debt there charged the P&L for income never taken while
 * leaving the obligation to refund standing at its full billed figure, for money the tenant never
 * paid. The balance sheet overstated what was owed to the tenant and the P&L took a phantom expense.
 *
 * **It is one of `deposits_tie_out`'s causes, not the only one** — stated rather than implied,
 * because a docblock claiming to unblock a red check is what stops the next person looking. The
 * same mistake is still live on the OTHER side: `CreditNoteJournalizer` debits `sales_returns`
 * (contra-REVENUE) for a security-deposit line, so a credited deposit invoice reds that check with
 * no write-off anywhere near it. Filed as its own row.
 *
 * The split comes from `DepositBilling::writeOffSplit()`, which applies the attribution this system
 * already states — a write-off reaches the deposit line only once every other outstanding line is
 * exhausted — rather than a second rule that could disagree with the lease page beside it. An
 * invoice carrying no deposit line answers a zero deposit share, so this is behaviour-identical on
 * every install that has never billed one.
 *
 * Dated at the write-off DECISION, not at the invoice — the loss belongs to the period the
 * operator recognised it, while the revenue stays in the period it was earned. That split is the
 * entire reason this is a write-off and not a cancellation: cancelling reverses revenue in the
 * current period, understating the year it was actually earned and hiding the bad debt as a
 * revenue reduction.
 */
class InvoiceWriteOffJournalizer implements Journalizer
{
    public function __construct(private AccountResolver $accounts) {}

    /**
     * Where a security-deposit line's credit went at issue — resolved exactly as
     * `InvoiceJournalizer` resolves it, with the same shipped floor.
     *
     * Keyed on the `security_deposit` TYPE rather than on "any line whose code posts to
     * `deposits_held`", which is the narrower question and is stated rather than implied: the whole
     * `DepositBilling` / `DepositHoldings` / `Lease` family keys on that type, so an operator-added
     * code pointed at the deposit liability (a `key_money`, say) is NOT split here and takes the
     * bad-debt debit. Moving all of them to a role-based test is the right answer and is a change to
     * the documents side as much as to this one.
     */
    private function depositRole(): string
    {
        // Shared with the credit-note journalizer (SW-238) — one resolution for one obligation.
        return DepositBilling::depositPostingRole();
    }

    public function payload(Model $source): ?array
    {
        /** @var InvoiceWriteOff $writeOff */
        $writeOff = $source;

        $amount = round((float) $writeOff->amount, 2);

        if ($amount <= 0) {
            return null;
        }

        $writeOff->loadMissing('invoice');
        $assetId = $writeOff->asset_id;

        $split = DepositBilling::writeOffSplit($writeOff);

        // Two debits or one. A zero-value line is omitted rather than posted at 0.00: `matches()`
        // compares the line set, so emitting an empty deposit line on every ordinary write-off
        // would make every historical entry look changed and mass void-and-repost them on the next
        // sweep — the hazard SW-134 records for a posting-role re-point.
        $debits = array_values(array_filter([
            $split['bad_debt'] > 0 ? [
                'ledger_account_id' => $this->accounts->id('bad_debt_expense', $assetId),
                'debit' => $split['bad_debt'],
                'credit' => 0,
                'tenant_id' => $writeOff->tenant_id,
            ] : null,
            $split['deposit'] > 0 ? [
                // The role the ISSUE resolved for that line, not a hardcoded `deposits_held`.
                // `charge_codes.posting_role` is operator-editable — `ChargeCodeForm` locks only
                // `code` and `is_active` for system codes — so an accountant who re-points
                // `security_deposit` would otherwise have the issue credit one account and the
                // write-off debit another, driving a liability negative that was never credited.
                // `CreditNoteJournalizer` states the same rule for the tax it reverses: a reversal
                // never re-classifies what it is reversing.
                'ledger_account_id' => $this->accounts->id($this->depositRole(), $assetId),
                'debit' => $split['deposit'],
                'credit' => 0,
                'tenant_id' => $writeOff->tenant_id,
            ] : null,
        ]));

        return [
            'entry_date' => $writeOff->entry_date,
            'description_en' => 'Bad debt write-off — invoice '.($writeOff->invoice?->number ?? "#{$writeOff->invoice_id}"),
            'description_ar' => 'إعدام دين — فاتورة '.($writeOff->invoice?->number ?? "#{$writeOff->invoice_id}"),
            // The narrative is a KEY resolved at READ time (EG-36); the prose above stays as
            // the snapshot and the floor for anything that does not go through the resolver.
            'description_key' => 'invoice.written_off',
            'description_data' => ['number' => $writeOff->invoice?->number ?? "#{$writeOff->invoice_id}"],
            'asset_id' => $assetId,
            'lines' => [
                ...$debits,
                [
                    'ledger_account_id' => $this->accounts->id('accounts_receivable', $assetId),
                    'debit' => 0,
                    'credit' => $amount,
                    'tenant_id' => $writeOff->tenant_id,
                    'lease_id' => $writeOff->invoice?->lease_id,
                ],
            ],
        ];
    }
}
