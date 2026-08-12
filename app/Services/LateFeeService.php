<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tenant;
use App\Notifications\LateFeeAppliedNotification;
use App\Support\OpsLog;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use App\Settings\BillingSettings;

class LateFeeService
{
    /**
     * How many invoices are hydrated at once by the nightly sweep.
     *
     * `protected` and read through `static::` so a test can lower it and actually exercise the
     * paging. At 250 the hazard this shape exists to avoid is unreachable in a fixture — a
     * `chunkById()` only re-queries when a page comes back FULL, so with a handful of rows it never
     * looks again and the bug hides. The backlog this runs against fills pages every night.
     */
    protected const CHUNK = 250;

    /**
     * Apply late fees to all invoices that are past (due_date + grace_days) and
     * not yet fully paid. Idempotent — invoices that already carry a `late_fee`
     * line item are skipped.
     *
     * @return array{considered:int, applied:int, skipped:int, failed:int}
     */
    public function runForToday(?CarbonImmutable $today = null): array
    {
        $today = $today ?? CarbonImmutable::now()->startOfDay();

        // Select every invoice that is merely PAST DUE and let `applyTo()` decide whether its own
        // lease's grace period has run out (story MF-08). Grace is a lease-level term now, so a
        // single global cutoff in this query would silently exclude the leases with the LONGEST
        // negotiated grace from ever being considered — the ones whose terms most needed honouring.
        // Over-selecting here is cheap; the precise rule lives in exactly one place.
        //
        // **A SNAPSHOT OF IDS, then chunks — not `->get()` of the whole thing.** Two reasons, and
        // the second is why this is not simply `chunkById()`:
        //
        //  1. Arrears is the one dataset that never shrinks. Hydrating every past-due invoice with
        //     its lease held the entire backlog in memory at 04:00, growing every month.
        //
        //  2. This loop CREATES invoices that match its own filter. A late fee is now its own
        //     invoice (see `applyTo`), issued today, due `today + payment_terms_days` — which on
        //     zero-day terms is due today, i.e. inside `due_date <= today`. `chunkById()` pages
        //     forward on ascending id, so it would walk straight into the fees it had just raised
        //     and consider charging a late fee on a late fee, in the same run. The old `->get()`
        //     was safe from that by accident, because it snapshotted first. Taking the ids up front
        //     keeps that property on purpose, and states why.
        $ids = Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance', '>', 0)
            ->whereDate('due_date', '<=', $today->toDateString())
            ->orderBy('id')
            ->pluck('id');

        $stats = ['considered' => $ids->count(), 'applied' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($ids->chunk(static::CHUNK) as $chunk) {
            $invoices = Invoice::query()->whereIn('id', $chunk)->with('lease')->get();

            foreach ($invoices as $invoice) {
                try {
                    $applied = $this->applyTo($invoice, $today);
                    if ($applied) {
                        $stats['applied']++;
                    } else {
                        $stats['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    OpsLog::error('Late fee application failed', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        OpsLog::info('Late fee batch complete', $stats);

        return $stats;
    }

    /**
     * Apply a late fee to one invoice. Returns true if a fee was added, false if it is not due yet
     * or was already applied (idempotent guard).
     *
     * **The rate, minimum and grace come from the LEASE** (`Lease::lateFeeTerms()`), falling back to
     * the portfolio default — see that method for why the fallback is `BillingSettings` and not
     * `config('billing.*')`.
     */
    public function applyTo(Invoice $invoice, ?CarbonImmutable $today = null): bool
    {
        $today = $today ?? CarbonImmutable::now()->startOfDay();
        $terms = $invoice->lease?->lateFeeTerms() ?? [
            'percent' => (float) app(\App\Settings\BillingSettings::class)->late_fee_percent,
            'grace_days' => (int) app(\App\Settings\BillingSettings::class)->late_fee_grace_days,
            'minimum' => (float) app(\App\Settings\BillingSettings::class)->late_fee_minimum,
        ];

        $percent = $terms['percent'];
        $min = $terms['minimum'];

        return DB::transaction(function () use ($invoice, $percent, $min, $terms, $today) {
            // Lock the invoice row and re-check the idempotency guard INSIDE the
            // transaction, so two concurrent late-fee runs can't both pass the
            // "no late_fee yet" check and double-charge the same invoice.
            $locked = Invoice::query()->lockForUpdate()->find($invoice->id);

            // Re-check the FULL precondition inside the lock, not just the late_fee
            // idempotency stamp: the outer query snapshotted this invoice as overdue
            // with balance > 0, but a payment captured between the snapshot and this
            // lock may have settled it. Charging a late fee on a now-paid invoice
            // would be wrong (and would still bill the minimum fee off a zero balance).
            // The idempotency stamp is now the LINK to the fee invoice, not a line on this one —
            // and a cancelled fee invoice does not count, so a fee raised in error can be voided
            // and re-charged. `items()->where('type','late_fee')` is deliberately still checked:
            // invoices charged under the old in-line behaviour must not be charged a second time.
            if (! $locked
                || $locked->hasLiveLateFee()
                || $locked->items()->where('type', 'late_fee')->exists()
                || (float) $locked->balance <= 0
                || ! in_array($locked->status, ['issued', 'partially_paid', 'overdue'], true)) {
                return false;
            }

            // This lease's own grace period. Checked HERE rather than in the batch query so the
            // single-invoice path (a manual action, a test) obeys the same rule the sweep does.
            if (blank($locked->due_date)
                || CarbonImmutable::instance($locked->due_date)->addDays($terms['grace_days'])->greaterThan($today)) {
                return false;
            }

            // ── A disputed line is not chargeable (story MF-07) ────────────────────────────────
            // Charging a penalty on a balance the tenant has formally disputed is the complaint this
            // story exists to stop. Only the OUTSTANDING part of a disputed line comes out — a line
            // that was part-paid is only argued about for what is still owed on it.
            $chargeable = round((float) $locked->balance - DisputeInvoiceItemService::disputedOutstanding($locked), 2);

            // Everything still owed is under dispute → no fee at all, and NOT the minimum. Falling
            // through to `max($min, 0)` would bill the floor off a balance nobody has agreed is
            // owed, which is precisely the charge this is meant to prevent.
            if ($chargeable <= 0) {
                return false;
            }

            $fee = max($min, round($chargeable * $percent / 100, 2));

            // A penalty is not consideration for a supply, so it ships outside the scope of VAT —
            // but that is the catalogue's ruling to state, not this service's. Reading it here is
            // what stops a hand-typed late-fee line and this one being taxed differently.
            $vatRate = Vat::rateForType('late_fee');
            $vat = Vat::atRate($fee, $vatRate);
            $total = round($fee + $vat, 2);

            // ── THE FEE IS ITS OWN INVOICE, DATED TODAY ───────────────────────────────────────
            //
            // It used to be appended as a line to the overdue invoice. `InvoiceJournalizer` dates
            // its entry from `issue_date`, so April's penalty landed as JANUARY revenue —
            // restating a month already closed, already reported to the owner and possibly already
            // filed, from an 04:00 cron with nobody watching. It also restated an issued document,
            // which is its own audit problem: the tenant's copy no longer matched ours.
            //
            // A separate dated invoice is the pattern this codebase already uses three times — CAM
            // true-ups, percentage-rent overages and violation fines all raise their own. The
            // period is THIS month because that is when the fee was incurred; `late_fee` is
            // excluded from `MonthlyBillingService`'s already-billed probe so it cannot suppress
            // the month's rent (the trap that bit `nsf_fee`).
            // `lease_id` is NOT NULL on invoices, so the lease is always there; the fallback is for
            // a lease that states no payment terms of its own.
            $dueInDays = (int) ($locked->lease->payment_terms_days ?? BillingSettings::defaultPaymentTermsDays());

            $feeInvoice = Invoice::create([
                'lease_id' => $locked->lease_id,
                'tenant_id' => $locked->tenant_id,
                'status' => 'issued',
                'issue_date' => $today,
                'due_date' => $today->addDays($dueInDays),
                'period_start' => $today->startOfMonth(),
                'period_end' => $today->endOfMonth(),
                'subtotal' => $fee,
                'vat_amount' => $vat,
                'total' => $total,
                'paid_amount' => 0,
                'balance' => $total,
                'currency' => $locked->currency ?? 'EGP',
            ]);

            InvoiceItem::create([
                'invoice_id' => $feeInvoice->id,
                // Spell out the basis so the operator (and the tenant on the invoice/PDF) can verify
                // the charge instead of seeing a bare "Late Fee" amount. It now also names the
                // invoice being penalised, which the line no longer sits on.
                'description' => __('admin.actions.late_fee_line_description', [
                    'percent' => rtrim(rtrim(number_format($percent, 2), '0'), '.'),
                    'balance' => 'EGP '.number_format($chargeable, 2),
                    'min' => 'EGP '.number_format((float) $min, 2),
                ]).' — '.$locked->number,
                'type' => 'late_fee',
                'amount' => $fee,
                'vat_rate' => $vatRate,
                'vat_amount' => $vat,
                'total' => $total,
            ]);

            // The link is the idempotency stamp AND the audit trail: it is what says WHY this
            // invoice exists. Written on the source, mirroring `Violation::billed_invoice_id`.
            // The overdue invoice's own totals are untouched — it stays exactly the document the
            // tenant was sent.
            $locked->late_fee_invoice_id = $feeInvoice->id;
            $locked->status = 'overdue';
            $locked->save();

            // Notify the tenant from INSIDE the transaction so the (queued) delivery
            // commits atomically with the fee — a crash or rollback loses both, so
            // the tenant can never be charged a late fee without being told. The
            // notification is ShouldQueue on the database queue, so this only writes
            // a job row here (no SMTP under the row lock).
            /** @var Tenant|null $tenant */
            $tenant = $locked->tenant;
            $tenant?->notifyPortal(new LateFeeAppliedNotification($feeInvoice, $locked));

            return true;
        });
    }
}
