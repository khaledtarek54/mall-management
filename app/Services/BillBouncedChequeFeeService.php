<?php

namespace App\Services;

use App\Contracts\BillableAgreement;
use App\Enums\InvoiceItemType;
use App\Enums\UnitOwnershipStatus;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\PostDatedCheque;
use App\Models\UnitOwnership;
use App\Support\PropertySettings;
use App\Support\Vat;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Charge the tenant for a returned cheque (module 33; Yardi posts an NSF charge).
 *
 * **The gap this closes.** A bounced cheque costs the landlord a bank return fee and the handling
 * that follows it, and Atriom absorbed both silently — Voyager posts an NSF charge as a matter of
 * course (02-yardi-money-flow.md §4). In Egypt a returned cheque is a serious event and recovering
 * the fee is ordinary practice.
 *
 * **An explicit operator action, never a side effect of `bounce()`.** That is the separation module
 * 31 already draws between recording a violation and billing its fine: the event is a fact, charging
 * for it is a decision, and a landlord may well waive the fee for a tenant whose cheque bounced once
 * in five years. `PostDatedChequeService::bounce()` stays a pure state change.
 *
 * **Nothing to reverse, unlike Yardi.** Voyager enters a receipt on deposit and reverses it on NSF,
 * re-opening every charge it had settled. Atriom creates no `Payment` until a cheque CLEARS, so the
 * tenant's invoice was never reduced and there is no AR to re-open — only the fee is new.
 */
class BillBouncedChequeFeeService
{
    public function bill(PostDatedCheque $cheque): Invoice
    {
        return DB::transaction(function () use ($cheque) {
            // Re-read under a row lock and re-check inside the transaction, so two clicks — or two
            // operators — cannot both mint an invoice for one bounce.
            $locked = PostDatedCheque::query()->lockForUpdate()->find($cheque->id);

            if (! $locked instanceof PostDatedCheque) {
                throw new DomainException(__('admin.post_dated_cheques.nsf_fee_failed_missing'));
            }

            // Already billed, and that invoice still claims AR — hand it back rather than raise a
            // second one. A CANCELLED fee invoice means the operator withdrew the charge, so
            // billing again is a legitimate re-charge.
            $existing = $locked->nsfFeeInvoice;

            if ($existing instanceof Invoice && $existing->status !== 'cancelled') {
                return $existing;
            }

            if ($locked->status !== PostDatedCheque::STATUS_BOUNCED) {
                throw new DomainException(__('admin.post_dated_cheques.nsf_fee_failed_not_bounced'));
            }

            // Per PROPERTY, falling back to the portfolio. The bounced-cheque fee is charged under
            // the same clause as the late fee and malls do not price it alike.
            //
            // Read off the cheque's OWN `asset_id`, which is required on the form and therefore
            // always present. It used to read `$locked->lease?->assetId()` — and since no UI path
            // ever sets `lease_id`, that silently resolved to null and priced every fee from the
            // portfolio default rather than the property's own.
            $fee = round((float) PropertySettings::get('billing.nsf_fee_amount', $locked->asset_id), 2);

            if ($fee <= 0) {
                throw new DomainException(__('admin.post_dated_cheques.nsf_fee_failed_not_configured'));
            }

            // The agreement to hang the AR on, RESOLVED here rather than read off the cheque.
            //
            // This used to be `$locked->lease` with a refusal beneath it, on the premise that "a
            // cheque always carries its own lease". It does not: neither the create form nor the
            // bulk-lodge action has a lease field, and nothing derives `lease_id` — so every cheque
            // an operator could produce carried null and the fee was unbillable in production. The
            // existing test missed it by setting `lease_id` directly in its fixture, a column no UI
            // path writes (the F-100 shape: ask whether the product could produce the state).
            //
            // Resolution order, most specific first. The invoice the cheque was taken against is the
            // best evidence — it already names its own agreement — then the party's standing
            // agreement in the cheque's property. An owner-occupier is reached by the same path he
            // is billed his صيانة on, because `IssueInvoiceService` takes a `BillableAgreement`
            // rather than a `Lease`.
            $agreement = $locked->lease
                ?? $locked->invoice?->lease
                ?? $locked->invoice?->unitOwnership
                ?? Lease::query()
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('status', 'active')
                    ->whereHas('unit', fn ($q) => $q->where('asset_id', $locked->asset_id))
                    ->latest('commencement_date')
                    ->first()
                ?? UnitOwnership::query()
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('asset_id', $locked->asset_id)
                    ->where('status', UnitOwnershipStatus::HandedOver->value)
                    ->covering(now())
                    ->latest('started_at')
                    ->first();

            // Neither a lease nor an ownership here: there is nothing to bill against, and inventing
            // an agreement would be worse than refusing.
            if (! $agreement instanceof BillableAgreement) {
                throw new DomainException(__('admin.post_dated_cheques.nsf_fee_failed_no_lease'));
            }

            $now = now();

            // A penalty is outside the scope of VAT, exactly like a violation fine — and, exactly
            // like it, that is the charge-code catalogue's ruling rather than this service's.
            $vatRate = Vat::rateForType(InvoiceItemType::NsfFee->value);
            $vat = Vat::atRate($fee, $vatRate);
            $total = round($fee + $vat, 2);

            $invoice = app(IssueInvoiceService::class)->issue(
                agreement: $agreement,
                items: [[
                    'description' => __('admin.post_dated_cheques.nsf_fee_line', $nsfLine = [
                        'cheque' => $locked->cheque_number,
                        'bank' => $locked->bank_name ?: '—',
                    ]),
                    // The DATA, so the tenant reads this in their own language (UX-30).
                    'description_key' => 'nsf_fee.line',
                    'description_data' => $nsfLine,
                    'type' => InvoiceItemType::NsfFee->value,   // → misc_income in the GL journalizer
                    'amount' => $fee,
                    'vat_rate' => $vatRate,
                    'vat_amount' => $vat,
                    'total' => $total,
                ]],
                issueDate: $now,
                // The month the cheque bounced in, which is when the cost was incurred — not the
                // month the operator got round to charging it.
                periodStart: $now->copy()->startOfMonth(),
                periodEnd: $now->copy()->endOfMonth(),
                // The debtor is stated on the cheque, not inferred from the lease it hangs off.
                tenantId: $locked->tenant_id,
            );

            $locked->update(['nsf_fee_invoice_id' => $invoice->id]);

            return $invoice;
        });
    }
}
