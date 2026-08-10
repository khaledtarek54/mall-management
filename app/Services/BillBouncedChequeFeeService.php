<?php

namespace App\Services;

use App\Enums\InvoiceItemType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PostDatedCheque;
use App\Settings\BillingSettings;
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

            $fee = round((float) app(BillingSettings::class)->nsf_fee_amount, 2);

            if ($fee <= 0) {
                throw new DomainException(__('admin.post_dated_cheques.nsf_fee_failed_not_configured'));
            }

            // AR hangs off a lease: the invoice's property derives from lease.unit.asset_id, which
            // is what keeps the charge in the mall the cheque was taken in. A cheque carries its own
            // lease, so unlike the violation fine there is nothing to infer.
            /** @var \App\Models\Lease|null $lease */
            $lease = $locked->lease;

            if ($lease === null) {
                throw new DomainException(__('admin.post_dated_cheques.nsf_fee_failed_no_lease'));
            }

            $now = now();

            $invoice = Invoice::create([
                'lease_id' => $lease->id,
                'tenant_id' => $locked->tenant_id,
                'status' => 'issued',
                'issue_date' => $now,
                'due_date' => $now->copy()->addDays($lease->payment_terms_days ?? 7),
                // The month the cheque bounced in, which is when the cost was incurred — not the
                // month the operator got round to charging it.
                'period_start' => $now->copy()->startOfMonth(),
                'period_end' => $now->copy()->endOfMonth(),
                'subtotal' => $fee,
                // A penalty is outside the scope of VAT, exactly like a violation fine.
                'vat_amount' => 0,
                'total' => $fee,
                'paid_amount' => 0,
                'balance' => $fee,
                'currency' => $lease->currency ?? 'EGP',
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => __('admin.post_dated_cheques.nsf_fee_line', [
                    'cheque' => $locked->cheque_number,
                    'bank' => $locked->bank_name ?: '—',
                ]),
                'type' => InvoiceItemType::NsfFee->value,   // → misc_income in the GL journalizer
                'amount' => $fee,
                'vat_rate' => 0,
                'vat_amount' => 0,
                'total' => $fee,
            ]);

            $locked->update(['nsf_fee_invoice_id' => $invoice->id]);

            return $invoice;
        });
    }
}
