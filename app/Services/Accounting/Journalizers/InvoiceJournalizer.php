<?php

namespace App\Services\Accounting\Journalizers;

use App\Models\ChargeCode;
use App\Models\Invoice;
use App\Services\Accounting\AccountResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Issue invoice (إصدار فاتورة):
 *   Dr Accounts Receivable (total)
 *   Cr revenue per item kind (ex-VAT)  +  Cr VAT Payable (total VAT)
 *
 * Revenue is recognized at issue. Cancelled invoices are skipped (their GL
 * treatment is a Phase-1b decision to confirm with the accountant).
 */
class InvoiceJournalizer implements Journalizer
{
    /**
     * invoice_item.type → semantic posting role. Unknown kinds fall back to `misc_income`.
     *
     * **Public because it is a registry, and gated as one.** `ChargeCodeGlMappingConformanceTest`
     * asserts that every `InvoiceItemType` case is either mapped here or listed in
     * {@see UNMAPPED_BY_DESIGN}, and that every role named here exists in `App\Support\PostingRoles`.
     *
     * Both halves matter. A new charge code added without a line here does not fail — it books
     * silently to miscellaneous income, so revenue is misclassified with nothing to notice it, which
     * is exactly why `violation_fine` and `nsf_fee` were mapped explicitly rather than left to the
     * fallback. And a typo'd role here throws "No account mapping for role …" at POSTING time, long
     * after the deploy; the gate turns that into a red build instead.
     *
     * @var array<string, string>
     */
    public const REVENUE_ROLE = [
        'base_rent' => 'rent_revenue',
        'service_charge' => 'service_charge_revenue',
        'utility' => 'utility_revenue',
        'parking' => 'parking_revenue',
        'percentage_rent' => 'percentage_rent_revenue',
        'marketing' => 'marketing_revenue',
        'late_fee' => 'late_fee_income',
        'cam_recovery' => 'cam_recovery_revenue',
        'cam_admin_fee' => 'cam_admin_fee_revenue',
        // A violation fine is a penalty, not consideration for a supply — it books to miscellaneous
        // (non-operating) income, and it is VAT-exempt (out of scope), unlike a service recharge.
        // Mapped explicitly (not left to the misc_income fallback) so it's intentional + reportable;
        // the accountant can reclassify to a dedicated penalty-income account later.
        'violation_fine' => 'misc_income',
        // Mapped explicitly for the same reason as the fine above: a returned-cheque fee is not
        // rent and not a late fee, and leaving it to the fallback would classify it correctly by
        // accident rather than on purpose.
        'nsf_fee' => 'misc_income',
    ];

    /**
     * Charge codes that deliberately have NO explicit role and take the `misc_income` fallback.
     *
     * Listing them is the point: it makes the fallback a decision someone made rather than a line
     * somebody forgot. Anything not here and not in {@see REVENUE_ROLE} fails the conformance gate.
     *
     * @var array<string, string> code => why it is deliberately unmapped
     */
    public const UNMAPPED_BY_DESIGN = [
        'other' => 'The escape hatch for an ad-hoc charge with no standing revenue account. It is unclassified BY DEFINITION — giving it a dedicated role would invite the operator to bill real, recurring revenue through a line nobody reports on.',
    ];

    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var Invoice $invoice */
        $invoice = $source;

        // Revenue is recognized at ISSUE — a draft (the default status) or a cancelled
        // invoice has no GL effect. All other statuses (issued/partially_paid/paid/
        // overdue/disputed/credited) keep their AR+revenue posting.
        if (in_array($invoice->status, ['draft', 'cancelled'], true)) {
            return null;
        }

        // A migrated OPENING ITEM is a sub-ledger document only.
        //
        // It is a real, live receivable — it ages, it is chased, a payment allocates to it — but
        // the revenue behind it was earned before Atriom existed, in the operator's previous
        // system, and is already inside the opening trial balance the accountant loads as one
        // manual journal entry. Posting it again would recognise the same revenue twice and
        // inflate AR to double the debt.
        //
        // The two sides still have to agree, and they do by construction: `glTieOut()` counts
        // these invoices in `expectedAr` while the opening entry supplies GL AR, so
        // `billing:reconcile` going green after a cutover is the statement "the receivables I
        // loaded equal the receivables my accountant says I have". A migration that quietly
        // loaded 90% of the debt is otherwise indistinguishable from one that worked.
        if ($invoice->is_opening_balance) {
            return null;
        }

        $invoice->loadMissing('items', 'lease.unit');
        $assetId = $invoice->lease?->unit?->asset_id;

        $revenueByRole = [];
        $vat = 0.0;
        /** @var \App\Models\InvoiceItem $item */
        foreach ($invoice->items as $item) {
            // The catalogue first (`charge_codes`, which an accountant maintains), then the
            // hard-coded map as a floor, then misc_income. The middle step is not redundant: a
            // deployment whose charge-code table has not been seeded yet — or a test that seeds
            // neither — must still post revenue to the right accounts rather than dump the lot into
            // miscellaneous income. `ChargeCodeGlMappingConformanceTest` asserts the two agree, so
            // the fallback is a safety net and never a second opinion.
            $code = (string) $item->type;
            $role = ChargeCode::roleFor($code)
                ?? self::REVENUE_ROLE[$code]
                ?? 'misc_income';
            $revenueByRole[$role] = ($revenueByRole[$role] ?? 0) + (float) $item->amount;
            $vat += (float) $item->vat_amount;
        }

        // Fallback for invoices with no line-item breakdown (legacy / header-only
        // data): classify the whole subtotal as unclassified operating revenue
        // (misc_income) + the header VAT, so the entry still balances to the total.
        // Real billed invoices always carry items, so this only guards odd data.
        if (round(array_sum($revenueByRole), 2) <= 0) {
            // Items present but no positive revenue = mis-typed / zero-amount items.
            // The entry will still balance + tie out, so flag it loudly rather than
            // letting a misclassification hide behind a green tie-out.
            if ($invoice->items->isNotEmpty()) {
                \Illuminate\Support\Facades\Log::warning(
                    "InvoiceJournalizer: invoice {$invoice->number} has items but no positive revenue; "
                    .'classifying the subtotal as misc_income — check the line items.'
                );
            }
            $revenueByRole = ['misc_income' => round((float) $invoice->subtotal, 2)];
            $vat = round((float) $invoice->vat_amount, 2);
        }

        $lines = [[
            'ledger_account_id' => $this->accounts->id('accounts_receivable', $assetId),
            'debit' => round((float) $invoice->total, 2),
            'credit' => 0,
            'tenant_id' => $invoice->tenant_id,
            'lease_id' => $invoice->lease_id,
        ]];

        foreach ($revenueByRole as $role => $amount) {
            $amount = round($amount, 2);
            if ($amount <= 0) {
                continue;
            }
            $lines[] = [
                'ledger_account_id' => $this->accounts->id($role, $assetId),
                'debit' => 0,
                'credit' => $amount,
                'tenant_id' => $invoice->tenant_id,
                'lease_id' => $invoice->lease_id,
            ];
        }

        if (round($vat, 2) > 0) {
            $lines[] = [
                'ledger_account_id' => $this->accounts->id('vat_payable', $assetId),
                'debit' => 0,
                'credit' => round($vat, 2),
            ];
        }

        return [
            'entry_date' => $invoice->issue_date,
            'description_en' => 'Invoice '.$invoice->number,
            'description_ar' => 'فاتورة '.$invoice->number,
            'asset_id' => $assetId,
            'lines' => $lines,
        ];
    }
}
