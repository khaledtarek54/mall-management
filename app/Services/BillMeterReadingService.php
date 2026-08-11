<?php

namespace App\Services;

use App\Support\Vat;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\MeterReading;
use App\Models\UtilityMeter;
use Illuminate\Support\Facades\DB;

/**
 * Recharge a metered utility reading to the tenant — the missing half of module 10 (readings were
 * recorded but could never be billed, so the operator re-keyed the cost onto an invoice by hand).
 *
 * Mirrors the percentage-rent / CAM immediate-billing pattern: the consumption month is in the PAST,
 * and the monthly billing engine only bills charges overlapping the run month, so a future-dated
 * one-off charge would be stranded. We therefore issue a dedicated recharge invoice NOW, dated to the
 * consumption period, carrying a single `utility` line → `utility_revenue` (41104001) via the existing
 * InvoiceJournalizer. (`utility` is excluded from MonthlyBillingService's already-billed probe so the
 * recharge can't suppress that month's base rent.)
 *
 * Idempotent: a reading already carrying a LIVE recharge invoice returns it untouched. Lock-safe.
 */
class BillMeterReadingService
{
    /** Utility recharge is a taxable supply (unlike base rent) — standard 14% output VAT. */

    /**
     * The lease that should be recharged for a reading — the one whose TERM CONTAINS the consumption
     * date, NOT simply the latest active lease on the unit. Billing "whoever is active now" would
     * charge the new tenant for the previous tenant's consumption (and would make a departed tenant's
     * reading unbillable — a silent revenue leak). Tie-break by latest commencement for safety on
     * overlapping data. Returns null for a common-area meter or an uncovered (vacant-period) date.
     */
    public static function resolveLeaseFor(MeterReading $reading): ?Lease
    {
        $meter = $reading->meter;
        if (! $meter instanceof UtilityMeter || $meter->unit_id === null) {
            return null;
        }

        $date = $reading->reading_date->toDateString();

        return Lease::query()
            ->where('unit_id', $meter->unit_id)
            ->whereDate('commencement_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $date))
            ->orderByDesc('commencement_date')
            ->first();
    }

    public function bill(MeterReading $reading): Invoice
    {
        return DB::transaction(function () use ($reading) {
            // Re-read under a row lock and re-check INSIDE the txn so two concurrent "Bill" clicks
            // can't both mint a recharge invoice for the same reading.
            $locked = MeterReading::query()->lockForUpdate()->find($reading->id);
            if (! $locked instanceof MeterReading) {
                throw new \DomainException(__('admin.utility.bill_failed_missing'));
            }

            // Already recharged and that invoice still posts revenue — return it, never double-bill.
            // ONLY a cancelled invoice (whose GL entry is voided) frees the reading to re-bill; a
            // credited invoice keeps its revenue posting, so re-billing it would double-count.
            $existing = $locked->billedInvoice;
            if ($existing instanceof Invoice && $existing->status !== 'cancelled') {
                return $existing;
            }

            $meter = $locked->meter;
            if (! $meter instanceof UtilityMeter || $meter->unit_id === null) {
                // A common-area / landlord meter has no tenant to recharge.
                throw new \DomainException(__('admin.utility.bill_failed_no_unit'));
            }

            $lease = self::resolveLeaseFor($locked);

            if (! $lease instanceof Lease) {
                throw new \DomainException(__('admin.utility.bill_failed_no_lease'));
            }

            $amount = round((float) $locked->cost, 2);
            if ($amount <= 0) {
                throw new \DomainException(__('admin.utility.bill_failed_zero_cost'));
            }

            // A utility recharge is a taxable supply — unless the catalogue says this mall's
            // accountant ruled otherwise. Both the rate and the amount come from one answer.
            $vatRate = Vat::rateForType('utility');
            $vat = Vat::atRate($amount, $vatRate);
            $now = now();
            $periodStart = $locked->reading_date->copy()->startOfMonth();
            $periodEnd = $locked->reading_date->copy()->endOfMonth();

            $invoice = Invoice::create([
                'lease_id' => $lease->id,
                'tenant_id' => $lease->tenant_id,
                'status' => 'issued',
                'issue_date' => $now,
                'due_date' => $now->copy()->addDays($lease->payment_terms_days ?? 7),
                // The CONSUMPTION period (truthful), not now() — see the probe-exclusion note above.
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'subtotal' => $amount,
                'vat_amount' => $vat,
                'total' => round($amount + $vat, 2),
                'paid_amount' => 0,
                'balance' => round($amount + $vat, 2),
                'currency' => $lease->currency ?? 'EGP',
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => __('admin.utility.recharge_line', [
                    'type' => __('admin.enums.meter_type')[$meter->type] ?? $meter->type,
                    'meter' => $meter->meter_number,
                    'consumption' => number_format((float) $locked->consumption, 2),
                    'uom' => $meter->unit_of_measurement ?: '',
                    'period' => $periodStart->isoFormat('MMM YYYY'),
                ]),
                'type' => 'utility', // → utility_revenue in the GL journalizer
                'amount' => $amount,
                'vat_rate' => $vatRate,
                'vat_amount' => $vat,
                'total' => round($amount + $vat, 2),
            ]);

            $locked->update(['billed_invoice_id' => $invoice->id, 'billed_at' => $now]);

            return $invoice;
        });
    }
}
