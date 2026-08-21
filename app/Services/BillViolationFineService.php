<?php

namespace App\Services;

use App\Contracts\BillableAgreement;
use App\Enums\InvoiceItemType;
use App\Enums\UnitOwnershipStatus;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\UnitOwnership;
use App\Models\Violation;
use App\Models\ViolationCategory;
use App\Support\Vat;
use Illuminate\Support\Facades\DB;

/**
 * Bill a recorded violation fine to the tenant — the missing half of module 31 (a fine was recorded
 * in `fine_amount` but never turned into AR, so the operator had no way to actually charge it).
 *
 * Mirrors the utility-recharge / CAM / percentage-rent immediate-billing pattern: issue a dedicated
 * invoice NOW carrying a single `violation_fine` line → misc_income (42101001) via the existing
 * InvoiceJournalizer. A fine is a PENALTY, not consideration for a supply, so it is VAT-EXEMPT (out
 * of scope) — unlike a utility recharge (14%). (`violation_fine` is excluded from
 * MonthlyBillingService's already-billed probe so a fine dated to the violation month can't suppress
 * that lease's base rent.)
 *
 * Idempotent + lock-safe: a violation already carrying a LIVE fine invoice returns it untouched; only
 * a CANCELLED invoice (whose GL entry is voided) frees the fine to re-bill.
 */
class BillViolationFineService
{
    public function bill(Violation $violation): Invoice
    {
        return DB::transaction(function () use ($violation) {
            // Re-read under a row lock + re-check inside the txn so two concurrent "Bill fine" clicks
            // can't both mint an invoice for the same violation.
            $locked = Violation::query()->lockForUpdate()->find($violation->id);
            if (! $locked instanceof Violation) {
                throw new \DomainException(__('admin.violations.bill_failed_missing'));
            }

            // Already billed and that invoice still posts revenue — return it, never double-bill.
            $existing = $locked->billedInvoice;
            if ($existing instanceof Invoice && $existing->status !== 'cancelled') {
                return $existing;
            }

            $fine = round((float) $locked->fine_amount, 2);
            if ($fine <= 0) {
                throw new \DomainException(__('admin.violations.bill_failed_no_fine'));
            }

            // AR needs a lease to attach to (the invoice's asset derives from lease.unit.asset_id).
            // Bill the tenant's ACTIVE lease in the property the violation happened in — so the AR is
            // property-scoped to the violation's own mall and can never leak to another.
            //
            // Latest-commencement wins if the tenant runs several active leases in the same mall. That
            // is deliberately simpler than the utility recharge's term-containment: a violation is
            // asset-scoped (not unit + time-specific like a meter reading), so there's no "correct"
            // unit/lease to prefer — the debtor and property are what matter, and both are always right.
            $agreement = Lease::query()
                ->where('tenant_id', $locked->tenant_id)
                ->where('status', 'active')
                ->whereHas('unit', fn ($q) => $q->where('asset_id', $locked->asset_id))
                ->latest('commencement_date')
                ->first();

            // An owner-occupier holds NO lease — module 37's other kind of occupier, who bought the
            // shop and trades from it himself. He is a `tenants` row like any other party, so the
            // violation register offers him and an operator can fine him; until 2026-08-18 that fine
            // was then unbillable, because this lookup was lease-shaped and nothing else was tried.
            //
            // Nothing downstream needed changing: `UnitOwnership implements BillableAgreement`, and
            // `IssueInvoiceService::issue()` takes the contract rather than a `Lease` — which is how
            // his monthly صيانة is already raised.
            //
            // Tenure-aware on the VIOLATION's date, not today: the party who owned the unit when it
            // happened is the party who owes the fine, so a later resale cannot move the debt onto
            // the buyer.
            if (! $agreement instanceof Lease) {
                $agreement = UnitOwnership::query()
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('asset_id', $locked->asset_id)
                    ->where('status', UnitOwnershipStatus::HandedOver->value)
                    ->covering($locked->violation_date)
                    ->latest('started_at')
                    ->first();
            }

            // Neither a lease nor an ownership here: there is no agreement to bill against, and
            // inventing one would be worse than refusing.
            if (! $agreement instanceof BillableAgreement) {
                throw new \DomainException(__('admin.violations.bill_failed_no_lease'));
            }

            $now = now();
            $periodStart = $locked->violation_date->copy()->startOfMonth();
            $periodEnd = $locked->violation_date->copy()->endOfMonth();

            // A fine is a penalty, not consideration for a supply, so it ships out of VAT scope —
            // stated on the charge code, where the accountant can rule otherwise without a deploy.
            $vatRate = Vat::rateForType(InvoiceItemType::ViolationFine->value);
            $vat = Vat::atRate($fine, $vatRate);
            $total = round($fine + $vat, 2);

            $invoice = app(IssueInvoiceService::class)->issue(
                agreement: $agreement,
                items: [[
                    'description' => __('admin.violations.fine_line', [
                        'reference' => $locked->reference,
                        'category' => ViolationCategory::labelFor($locked->category),
                        'date' => $locked->violation_date->isoFormat('D MMM YYYY'),
                    ]),
                    'type' => InvoiceItemType::ViolationFine->value, // → misc_income in the GL journalizer
                    'amount' => $fine,
                    'vat_rate' => $vatRate,
                    'vat_amount' => $vat,
                    'total' => $total,
                ]],
                issueDate: $now,
                // The violation's month (truthful), not now() — see the probe-exclusion note above.
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                // The debtor is stated on the violation, not inferred from the lease it was matched to.
                tenantId: $locked->tenant_id,
            );

            $locked->update(['billed_invoice_id' => $invoice->id, 'billed_at' => $now]);

            return $invoice;
        });
    }
}
