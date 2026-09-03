<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Notifications\LateFeeAppliedNotification;
use App\Settings\BillingSettings;
use App\Support\OpsLog;
use App\Support\PropertySettings;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
            ->chaseable()
            ->whereDate('due_date', '<=', $today->toDateString())
            ->orderBy('id')
            ->pluck('id');

        $stats = ['considered' => $ids->count(), 'applied' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($ids->chunk(static::CHUNK) as $chunk) {
            // Both agreements eager-loaded: an invoice carries a lease OR a unit ownership, and
            // loading only the lease left every owner assessment issuing a query per row for the
            // other half.
            $invoices = Invoice::query()->whereIn('id', $chunk)->with(['lease', 'unitOwnership'])->get();

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
     * **The rate, minimum, grace AND CAP come from the LEASE** (`Lease::lateFeeTerms()`), falling back to
     * the portfolio default — see that method for why the fallback is `BillingSettings` and not
     * `config('billing.*')`.
     */
    public function applyTo(Invoice $invoice, ?CarbonImmutable $today = null): bool
    {
        $today = $today ?? CarbonImmutable::now()->startOfDay();
        // The AGREEMENT's terms, falling back to the tiers `PropertySettings` already resolves.
        //
        // An owner assessment states no late-fee clause of its own — a unit ownership has no
        // negotiated grace or rate — so the fallback is its real path, not a fixture allowance. The
        // note that used to sit here said `invoices.lease_id` was NOT NULL and that this branch only
        // ran for a detached fixture; that stopped being true when module 37 shipped.
        //
        // **The property is `$invoice->asset_id`, and passing null here was a real defect.** All
        // five keys are `PropertySettings::OVERRIDABLE`, so a null asset id answers at the PORTFOLIO
        // tier and silently discards the mall's own clause — and `grace_days` decides *whether* a
        // fee is charged at all, not merely how much. The invoice carries the property directly
        // (`Invoice::creating` refuses to save without one), which is exactly what denormalising it
        // was for. This is the same defect `BillBouncedChequeFeeService` records having fixed:
        // reading the property through a lease that owner invoices do not have, and pricing every
        // fee from the portfolio default.
        $terms = $invoice->lease?->lateFeeTerms() ?? [
            'percent' => (float) PropertySettings::get('billing.late_fee_percent', $invoice->asset_id),
            'grace_days' => (int) PropertySettings::get('billing.late_fee_grace_days', $invoice->asset_id),
            'minimum' => (float) PropertySettings::get('billing.late_fee_minimum', $invoice->asset_id),
            // 0 = no ceiling, which is what every install had before EG-35 and therefore what an
            // unset value must keep meaning.
            'maximum' => (float) PropertySettings::get('billing.late_fee_maximum', $invoice->asset_id),
            'recurrence_days' => (int) PropertySettings::get('billing.late_fee_recurrence_days', $invoice->asset_id),
        ];

        $percent = $terms['percent'];
        $min = $terms['minimum'];
        $max = $terms['maximum'];

        return DB::transaction(function () use ($invoice, $percent, $min, $max, $terms, $today) {
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
                || ! $this->mayChargeAgain($locked, (int) $terms['recurrence_days'], $today)
                // ABSOLUTE bar, for two reasons that coincide: an invoice charged under the old
                // in-line behaviour must not be charged again, and a FEE INVOICE's only line is
                // itself of type `late_fee` — so this is also what stops a late fee earning one.
                // Recurrence must never reach through it.
                || $locked->items()->where('type', 'late_fee')->exists()
                || $locked->collectableBalance() <= 0
                // The row twin of `->chaseable()`: LIVE, and not under dispute. A hand-kept list
                // here excluded `disputed` only by accident of which three statuses got copied;
                // now it says so, and it cannot drift from the query that selected the batch.
                || ! $locked->isChaseable()) {
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
            // The invoice answers what it is worth; this service does no arithmetic about it.
            // `chargeableBalance()` takes the forgiven slice off FIRST and then the disputed one,
            // flooring at zero — so a debt that is wholly written off and partly disputed cannot
            // produce a negative base that the minimum-fee `max()` would round back up into a charge.
            $chargeable = $locked->chargeableBalance();

            // Everything still owed is under dispute → no fee at all, and NOT the minimum. Falling
            // through to `max($min, 0)` would bill the floor off a balance nobody has agreed is
            // owed, which is precisely the charge this is meant to prevent.
            if ($chargeable <= 0) {
                return false;
            }

            $fee = max($min, round($chargeable * $percent / 100, 2));

            // The clause's ceiling (EG-35, finding M-8). A percentage of an arrears has no upper
            // bound, so a tenant six months behind on a large invoice drew a penalty proportional
            // to the debt rather than to the breach — and a real clause caps it.
            //
            // Applied AFTER the minimum, deliberately: with a cap below the floor the cap wins,
            // because a ceiling the operator typed is a statement about the most they will charge
            // and a floor is only a statement about rounding up small ones. `max()` last would
            // bill above a cap the clause names.
            if ($max > 0) {
                $fee = min($fee, $max);
            }

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
            // The AGREEMENT, not the lease. `invoices.lease_id` stopped being NOT NULL when module
            // 37 introduced a party who holds no lease, and the comment that used to sit here said
            // the opposite — so this line threw on every overdue owner assessment the sweep reached.
            //
            // `runForToday()` catches per invoice, so the run itself survived and the others were
            // still charged: what was lost was the OWNER's fee, every night, for ever, counted as
            // `failed` in a 04:00 log line that names an invoice id and no reason. A debt that is
            // never chased and never reported is the failure nobody opens a ticket for. Measured on
            // the demo books: 48 invoices carry no lease and every one of them would reach this line
            // the day it falls due.
            //
            // `agreement()` answers a lease or an ownership and both implement the whole
            // `BillableAgreement` contract, so nothing below needs a branch: the fee invoice is
            // raised against whichever agreement owed the money.
            $agreement = $locked->agreement();

            if ($agreement === null) {
                // A saved row always names an agreement (`assertBelongsToExactlyOneAgreement`) and
                // `Invoice::agreement()` reads it `withTrashed()`, so this is unreachable in
                // practice. It is LOGGED rather than silently skipped anyway, because `false` means
                // "nothing to charge here" to the caller and would file a real defect under
                // `skipped` — turning the loud failure this whole change is about into a quiet one.
                OpsLog::error('Late fee skipped: invoice names no agreement', [
                    'invoice_id' => $locked->id,
                    'number' => $locked->number,
                ]);

                return false;
            }

            $dueInDays = $agreement->paymentTermsDays();

            $feeInvoice = app(IssueInvoiceService::class)->issue(
                agreement: $agreement,
                items: [[
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
                ]],
                issueDate: $today,
                periodStart: $today->startOfMonth(),
                periodEnd: $today->endOfMonth(),
                dueDate: $today->addDays($dueInDays),
                // The debtor is stated on the invoice being penalised, not inferred from its lease.
                tenantId: $locked->tenant_id,
                // A penalty is denominated in the currency of the debt it penalises.
                currency: $locked->currency ?? 'EGP',
            );

            // The link is the idempotency stamp AND the audit trail: it is what says WHY this
            // invoice exists. Written on the source, mirroring `Violation::billed_invoice_id`.
            // The overdue invoice's own totals are untouched — it stays exactly the document the
            // tenant was sent.
            // The fee points BACK at what it penalises — the audit trail, and what makes "which
            // fees came from this invoice" answerable once there can be more than one (EG-35).
            $feeInvoice->late_fee_for_invoice_id = $locked->id;
            $feeInvoice->save();

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

    /**
     * May this invoice be charged a late fee now, given what it has already been charged? (EG-35)
     *
     * Three states, and the first two are exactly what the system did before recurrence existed:
     *
     *  - **no live fee** — charge. A CANCELLED fee does not count, which is what lets one raised in
     *    error be voided and re-charged.
     *  - **a live fee and `$recurrenceDays` of 0** — refuse. One fee per invoice: the shipped
     *    behaviour, and what every install still gets until a clause says otherwise.
     *  - **a live fee and a window** — charge only once that window has fully elapsed since the
     *    last fee was ISSUED.
     *
     * Measured from the last fee's `issue_date`, not from the invoice's due date. The clause says
     * "again every N days"; anchoring to the due date would fire a burst of back-dated fees the
     * first time an old arrear is swept.
     */
    private function mayChargeAgain(Invoice $invoice, int $recurrenceDays, CarbonImmutable $today): bool
    {
        $last = $invoice->latestLiveLateFee();

        if ($last === null) {
            return true;
        }

        if ($recurrenceDays <= 0 || blank($last->issue_date)) {
            return false;
        }

        return CarbonImmutable::instance($last->issue_date)
            ->addDays($recurrenceDays)
            ->lessThanOrEqualTo($today);
    }
}
