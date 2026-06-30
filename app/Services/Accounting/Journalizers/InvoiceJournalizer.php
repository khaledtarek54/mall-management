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
        'percentage_rent' => 'percentage_rent_revenue',
        'marketing' => 'marketing_revenue',
        'late_fee' => 'late_fee_income',
    ];

    public function __construct(private AccountResolver $accounts) {}

    public function payload(Model $source): ?array
    {
        /** @var Invoice $invoice */
        $invoice = $source;

        if ($invoice->status === 'cancelled') {
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
