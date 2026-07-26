<?php

namespace App\Services\Accounting\Journalizers;

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
    /** invoice_item.type → semantic revenue role. Unknown kinds fall back to misc_income. */
    private const REVENUE_ROLE = [
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

        $invoice->loadMissing('items', 'lease.unit');
        $assetId = $invoice->lease?->unit?->asset_id;

        $revenueByRole = [];
        $vat = 0.0;
        foreach ($invoice->items as $item) {
            $role = self::REVENUE_ROLE[$item->type] ?? 'misc_income';
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
