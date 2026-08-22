<?php

namespace App\Services\Reports;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Vendor;
use App\Models\VendorBillPayment;
use App\Services\Accounting\AccountResolver;
use App\Support\WithholdingTax;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * نموذج ٤١ — the withholding-tax return position for a quarter, and the per-supplier detail it is
 * filed from.
 *
 * The withholding ENGINE was complete and dated — per-vendor code → portfolio default → nothing,
 * resolved for the payment's date, charged on the VAT-exclusive share, posting
 * `Cr withholding_tax_payable`. What was missing was the artefact: Egypt files this **quarterly on
 * Form 41**, listing every supplier withheld from, and the supplier needs a certificate to claim
 * the amount against their own corporate income tax. Neither existed, and that is what kept
 * `TaxSettings::wht_enabled` switched off — an operator cannot start withholding money they have no
 * way to declare or to evidence.
 *
 * ## Filed per REGISTRATION, not per property
 *
 * `$assetId` is accepted for symmetry with the other report services and should stay null in the
 * page: the operator files one return under one tax registration covering the whole portfolio, so a
 * per-mall figure is not a thing anyone can file. Same decision, and the same reason, as
 * {@see VatReturnService}.
 *
 * ## The tie-out is the point
 *
 * Two independent sides. The DOCUMENTS side sums `vendor_bill_payments.withholding_amount` over the
 * period — what the operator actually deducted from suppliers, and the only place the per-supplier
 * detail exists. The LEDGER side reads the credit movement on `withholding_tax_payable` — what the
 * books say is owed to the ETA. They must agree, and when they do not, something was withheld and
 * not posted (or posted twice) before a number becomes a filing position somebody signs.
 *
 * A cancelled payment is excluded from both sides: `VendorBillPayment` soft-deletes on void and
 * `LedgerPoster::sync()` voids its entry, so the ledger drops it too.
 *
 * ## What this deliberately does NOT do
 *
 * It does not file anything, and it does not produce the ETA's own XML or its printed layout — the
 * form's exact boxes are the accountant's, and inventing them would be a document that looks
 * official and is not. It reports the position and the detail behind it, which is what an
 * accountant reconciles their own filing against.
 */
class WithholdingTaxReturnService
{
    public function __construct(private AccountResolver $accounts) {}

    /**
     * @return array{
     *     period_start: string, period_end: string,
     *     withheld_documents: float, withheld_ledger: float, difference: float, ties_out: bool,
     *     remitted: float, outstanding: float,
     *     suppliers: array<int, array{
     *         vendor_id: int, vendor: string, tax_id: ?string, tax_code: ?string,
     *         base: float, withheld: float, payments: int, effective_rate: float,
     *     }>,
     * }
     */
    public function for(CarbonImmutable $start, CarbonImmutable $end, ?int $assetId = null): array
    {
        $payments = VendorBillPayment::query()
            ->where('withholding_amount', '>', 0)
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->when($assetId, fn ($q) => $q->whereHas('bill', fn ($b) => $b->where('asset_id', $assetId)))
            ->with(['bill.vendor'])
            ->get();

        $suppliers = [];
        $withheldDocuments = 0.0;

        foreach ($payments as $payment) {
            $vendor = $payment->bill?->vendor;

            if (! $vendor instanceof Vendor) {
                // A payment whose bill or vendor has gone. It still moved money and still owes the
                // ETA, so it counts toward the total — it just cannot be attributed to a supplier
                // line, and the tie-out below is what makes that visible rather than silent.
                $withheldDocuments += round((float) $payment->withholding_amount, 2);

                continue;
            }

            $withheld = round((float) $payment->withholding_amount, 2);
            $withheldDocuments += $withheld;

            // The BASE the withholding was charged on, recovered from the amount and the rate that
            // was actually applied — not recomputed from today's catalogue. A rate revised since
            // the payment must not rewrite what was withheld, exactly as an issued invoice keeps
            // the VAT rate it was billed at.
            $base = WithholdingTax::vatExclusiveShareOf((float) $payment->amount, $payment->bill);

            $row = $suppliers[$vendor->id] ?? [
                'vendor_id' => $vendor->id,
                'vendor' => $vendor->name,
                'tax_id' => $vendor->tax_id,
                'tax_code' => $vendor->withholding_tax_code,
                'base' => 0.0,
                'withheld' => 0.0,
                'payments' => 0,
                'effective_rate' => 0.0,
            ];

            $row['base'] = round($row['base'] + $base, 2);
            $row['withheld'] = round($row['withheld'] + $withheld, 2);
            $row['payments']++;

            $suppliers[$vendor->id] = $row;
        }

        foreach ($suppliers as $id => $row) {
            // Reported rather than assumed: several payments to one supplier in a quarter can carry
            // different rates (a rate change mid-quarter, or a code corrected on the vendor), and a
            // single "rate" column would be a guess. This is what was withheld over what it was
            // withheld from, which is the only rate the return can honestly state per supplier.
            $suppliers[$id]['effective_rate'] = $row['base'] > 0
                ? round($row['withheld'] / $row['base'] * 100, 2)
                : 0.0;
        }

        usort($suppliers, fn (array $a, array $b) => $b['withheld'] <=> $a['withheld']);

        $withheldLedger = $this->accountMovement('withholding_tax_payable', $start, $end, $assetId, credits: true);

        // What has been PAID OVER to the ETA in the period — the debit side of the same liability.
        // A remittance is an ordinary payment out that debits this account, so the outstanding
        // figure is what the operator is still holding on the tax authority's behalf.
        $remitted = $this->accountMovement('withholding_tax_payable', $start, $end, $assetId, credits: false);

        $difference = round($withheldDocuments - $withheldLedger, 2);

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'withheld_documents' => round($withheldDocuments, 2),
            'withheld_ledger' => $withheldLedger,
            'difference' => $difference,
            'ties_out' => abs($difference) < 0.01,
            'remitted' => max(0.0, $remitted),
            'outstanding' => round($withheldLedger - max(0.0, $remitted), 2),
            'suppliers' => array_values($suppliers),
        ];
    }

    /**
     * One supplier's withholding over a period — what a certificate is issued from.
     *
     * Separate from {@see for()} rather than filtered out of it, because a certificate is a document
     * about one supplier and building it by scanning the whole quarter would make issuing fifty
     * certificates fifty full scans.
     *
     * @return array{
     *     vendor_id: int, vendor: string, tax_id: ?string, tax_code: ?string,
     *     base: float, withheld: float, effective_rate: float,
     *     lines: array<int, array{date: string, reference: ?string, base: float, withheld: float}>,
     * }
     */
    public function forVendor(Vendor $vendor, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $payments = VendorBillPayment::query()
            ->where('withholding_amount', '>', 0)
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('bill', fn ($q) => $q->where('vendor_id', $vendor->id))
            ->with('bill')
            ->orderBy('payment_date')
            ->get();

        $lines = [];
        $base = 0.0;
        $withheld = 0.0;

        foreach ($payments as $payment) {
            $lineBase = WithholdingTax::vatExclusiveShareOf((float) $payment->amount, $payment->bill);
            $lineWithheld = round((float) $payment->withholding_amount, 2);

            $base = round($base + $lineBase, 2);
            $withheld = round($withheld + $lineWithheld, 2);

            $lines[] = [
                'date' => $payment->payment_date?->toDateString() ?? '',
                'reference' => $payment->bill?->number,
                'base' => $lineBase,
                'withheld' => $lineWithheld,
            ];
        }

        return [
            'vendor_id' => $vendor->id,
            'vendor' => $vendor->name,
            'tax_id' => $vendor->tax_id,
            'tax_code' => $vendor->withholding_tax_code,
            'base' => $base,
            'withheld' => $withheld,
            'effective_rate' => $base > 0 ? round($withheld / $base * 100, 2) : 0.0,
            'lines' => $lines,
        ];
    }

    /**
     * Net movement on a semantic account role over the period, on the side asked for.
     *
     * Reads POSTED and VOID entries ({@see JournalEntry::REPORTABLE_STATUSES}) for the reason
     * {@see VatReturnService} does: a corrected vendor bill would otherwise take its withholding out
     * of the period entirely and understate what is owed to the tax authority.
     */
    private function accountMovement(string $role, CarbonImmutable $start, CarbonImmutable $end, ?int $assetId, bool $credits): float
    {
        $accountId = $this->accounts->id($role, $assetId);

        $expression = $credits
            ? 'COALESCE(credit, 0) - COALESCE(debit, 0)'
            : 'COALESCE(debit, 0) - COALESCE(credit, 0)';

        return round((float) JournalLine::query()
            ->where('ledger_account_id', $accountId)
            ->when($assetId, fn ($q) => $q->where('asset_id', $assetId))
            ->whereHas('entry', fn ($q) => $q
                ->whereIn('status', JournalEntry::REPORTABLE_STATUSES)
                ->whereDate('entry_date', '>=', $start->toDateString())
                ->whereDate('entry_date', '<=', $end->toDateString()))
            ->sum(DB::raw($expression)), 2);
    }
}
