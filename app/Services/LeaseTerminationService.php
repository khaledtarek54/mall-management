<?php

namespace App\Services;

use App\Support\LeaseEventNarrative;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\LeaseEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LeaseTerminationService
{
    /**
     * Terminate an active lease early.
     *
     * - Marks lease status = 'terminated', stores termination date + reason
     * - Unit status is recomputed by LeaseObserver — falls to 'reserved' if
     *   another draft/pending lease exists on the unit, else 'vacant'
     * - Deactivates the lease's recurring charges (is_active = false)
     * - Optionally cancels open invoices (status = 'cancelled', balance = 0)
     * - Credits back the unearned part of any invoice billed past the termination date (MF-02)
     *
     * @param  array{termination_date:string|\DateTimeInterface|null, reason:string|null, cancel_open_invoices?:bool, credit_unearned?:bool}  $data
     */
    public function terminate(Lease $lease, array $data): Lease
    {
        if (! in_array($lease->status, ['active', 'pending_approval'], true)) {
            throw new InvalidArgumentException("Lease #{$lease->id} is '{$lease->status}'; only active leases can be terminated.");
        }

        $terminationDate = isset($data['termination_date']) && $data['termination_date']
            ? CarbonImmutable::parse($data['termination_date'])
            : CarbonImmutable::now()->startOfDay();

        $reason = trim((string) ($data['reason'] ?? ''));
        $cancelOpenInvoices = (bool) ($data['cancel_open_invoices'] ?? false);
        $creditUnearned = (bool) ($data['credit_unearned'] ?? true);

        return DB::transaction(function () use ($lease, $terminationDate, $reason, $cancelOpenInvoices, $creditUnearned) {
            // 1. Lease itself
            $contractedExpiry = $lease->expiry_date
                ? CarbonImmutable::parse($lease->expiry_date)->toDateString()
                : null;

            // ── A TERMINATION DATED IN THE FUTURE IS NOTICE, AND A LEASE UNDER NOTICE STILL BILLS ──
            //
            // The field offers any date from commencement onward and has no upper bound, which is
            // right — a tenant gives notice months before they go, and recording it the day they
            // hand it in is the whole point. What was wrong is what happened next: the status went
            // to `terminated` and EVERY charge row was deactivated immediately, so two independent
            // blockers stopped the billing at once — `isBillableForPeriod()` refuses a lease that
            // is not `active`, and the planner skips an inactive charge row.
            //
            // Measured: a lease terminated on 30 August effective 30 November stopped billing in
            // September. Three months of rent the tenant genuinely owes, never invoiced, with
            // nothing on any screen to say a lease had gone quiet.
            //
            // Under notice the lease stays ACTIVE and the charge rows stay live with an `end_date`
            // — the schedule is date-ranged, so they stop themselves on the day. `leases:expire`
            // then closes the lease and frees the unit, exactly as it does for a term that runs
            // out, and it reads the termination event to close it as `terminated` rather than
            // `expired`. Yardi's model: notice given, then moved out; two states, not one.
            $underNotice = $terminationDate->isAfter(CarbonImmutable::today());

            $existingNotes = $lease->notes ? rtrim($lease->notes)."\n\n" : '';
            $stamp = $terminationDate->format('Y-m-d');
            $reasonLine = $reason !== '' ? "Terminated on {$stamp}: {$reason}" : "Terminated on {$stamp}.";
            $lease->update([
                // `expiry_date` moves either way — that IS when the tenancy ends, and it is what
                // `leases:expire` and every projection read.
                'status' => $underNotice ? $lease->status : 'terminated',
                'expiry_date' => $terminationDate,
                'notes' => $existingNotes.$reasonLine,
            ]);

            // 2. Unit status is recomputed by LeaseObserver from step 1. Under notice the lease is
            //    still active, so the unit stays occupied until the day — which is true.

            // 3. Close the charge rows ON the termination date. `is_active` is only dropped once
            //    that date has passed: a live row with an `end_date` bills up to it and no further
            //    (MonthlyBillingService skips a charge whose `end_date` precedes the period), so
            //    the flag is about a row that is finished, not one that is going to be.
            //
            //    A ROW THAT ALREADY CLOSED IS LEFT ALONE. The blanket `update()` here wrote the
            //    termination date over EVERY row, which was invisible while they were all being
            //    deactivated in the same statement — and the moment they stay live it re-opens the
            //    closed rungs of a rent ladder. Measured on demo lease #6: `base_rent 50,000`
            //    closed on 31/07/2026 had its end date pushed to 30/11/2026, overlapping the
            //    51,500 row that succeeded it, and the billing run threw for every month after.
            //
            //    So only rows that would otherwise run PAST the termination are closed at it. This
            //    is the same rule `ChargeScheduleService` keeps — one row per type covers any
            //    period — and the reason `atriom:audit-charge-schedules` exists.
            Charge::where('lease_id', $lease->id)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>', $terminationDate->toDateString()))
                ->update(['end_date' => $terminationDate]);

            // Deactivation is the other half and applies to the WHOLE schedule: once the tenancy is
            // over, nothing on it is live any more, closed rungs included.
            if (! $underNotice) {
                Charge::where('lease_id', $lease->id)->update(['is_active' => false]);
            }

            // 4. Cancel open invoices if requested — but only fully unpaid
            // ones. A partially-paid invoice that we silently cancelled would
            // orphan the tenant's paid_amount (they'd have paid into a
            // record that no longer claims any balance). Operators who want
            // to void a partially-paid invoice must issue a credit note for
            // the paid portion explicitly — that keeps the AR ledger honest.
            //
            // ── AND ONLY WHAT WAS NEVER EARNED ─────────────────────────────────────────────────
            // This used to cancel every fully-unpaid open invoice on the lease, whatever period it
            // covered — which on a system that bills IN ADVANCE destroys revenue the landlord has
            // already earned. Reproduced on a quarterly lease terminating mid-quarter: the Oct–Dec
            // invoice (253,260, of which 126,630 was earned by 15 November), October's percentage
            // rent (70,000, a month entirely in the past) and November's were all cancelled to a
            // zero balance. The tenant occupied the space and traded from it, and owed nothing.
            //
            // Step 5 below exists precisely to handle the straddling case — it credits the unearned
            // fraction using the same month-share rule the invoice was billed on. Cancelling the
            // whole document first left it nothing to credit, so the two steps were not merely
            // ordered wrongly: the first made the second unreachable.
            //
            // The rule is the period, not the balance:
            //   period starts AFTER the termination  → nothing was earned  → cancel
            //   period STRADDLES the termination     → partly earned       → leave it; step 5 credits
            //   period ends BEFORE the termination   → fully earned        → leave it owing
            //
            // The `whereNotNull` is belt-and-braces: `invoices.period_start` is NOT NULL today, so
            // that state is unreachable and deliberately has no test — a test over an impossible
            // input is green over dead code. The guard stays because the rule it expresses is the
            // safe one: never wipe a receivable that cannot be proven unearned.
            $cancelledNumbers = [];

            if ($cancelOpenInvoices) {
                Invoice::where('lease_id', $lease->id)
                    ->whereIn('status', ['draft', 'issued', 'partially_paid', 'overdue'])
                    ->where('balance', '>', 0)
                    ->where('paid_amount', '=', 0)
                    ->whereNotNull('period_start')
                    ->whereDate('period_start', '>', $terminationDate->toDateString())
                    // Never silently cancel an invoice already filed with the tax authority
                    // (eta_status = 'valid') — every other cancel path (VoidInvoiceService,
                    // EditInvoice, InvoicesTable) refuses it; the operator must handle a
                    // reported invoice through the proper ETA flow. Nulls stay cancellable.
                    ->where(fn ($q) => $q->whereNull('eta_status')->orWhere('eta_status', '!=', 'valid'))
                    ->each(function (Invoice $invoice) use (&$cancelledNumbers) {
                        $invoice->update([
                            'status' => 'cancelled',
                            'balance' => 0,
                        ]);

                        $cancelledNumbers[] = $invoice->number;
                    });
            }

            // 5. Credit back what was billed in advance and will never be earned (story MF-02).
            //
            // Rent bills on the 1st, so a tenant leaving on the 18th has already been invoiced for
            // the whole month. Trailing proration cannot fix an invoice that already exists — only
            // a credit note can — and until now raising it was a manual act somebody had to
            // remember. The credit uses the same month-fraction rule the invoice used, so it is the
            // exact complement rather than an independent day-count.
            //
            // Opt-OUT rather than opt-in: giving back money the tenant does not owe is the correct
            // default. The flag exists because the note posts on the termination date, and a CLOSED
            // period refuses it — an operator who has to terminate today and credit later needs a
            // way through that does not involve reopening the books.
            $credits = [];

            if ($creditUnearned) {
                $credits = app(CreditUnearnedBillingService::class)->forTermination($lease->fresh(), $terminationDate);
            }

            // ── THE LEASE'S OWN HISTORY MUST RECORD THAT IT ENDED ───────────────────────────────
            //
            // `lease_events` has carried a `termination` type since it shipped, and only two
            // services ever wrote one: `ExerciseLeaseOptionService` (a break option) and
            // `SettleMoveOutService` (the final account). The ordinary Terminate button wrote
            // none — so a lease that ended and was never settled, which is every lease with no
            // deposit to return, has an append-only history showing its extensions and its
            // abatements and nothing at all about the day it ended.
            //
            // Measured on demo lease #3 after terminating it: the history read
            // `rent_modification · abatement · extension` and stopped. The activity trail says
            // only "lease updated".
            //
            // The final account still records its OWN event and must — they are two acts, not
            // one. This says the tenancy ended on a date; that one says the account was struck
            // and freezes the figures. `SettleMoveOutService`'s payload carries `settlement: true`
            // and this one does not, which is how a reader tells them apart.
            //
            // The payload is what a later reader cannot reconstruct: which documents were
            // withdrawn because they covered a period nobody occupied, and what was credited back
            // on the one that straddled the date. The invoices themselves are `cancelled` and the
            // credit notes exist, but nothing else says they happened BECAUSE of this termination.
            app(RecordLeaseEventService::class)->record(
                $lease,
                LeaseEvent::TYPE_TERMINATION,
                $terminationDate,
                // The operator's words, or none. Translating a default here stored the sentence
                // in whichever language this run happened to be in — measured: an Arabic row on an
                // otherwise English history. The narrative key in the payload is what a reader
                // composes from.
                $reason !== '' ? $reason : null,
                [
                    LeaseEventNarrative::KEY => 'lease_terminated',
                    'cancelled_invoices' => $cancelledNumbers,
                    'credit_notes' => collect($credits)->pluck('number')->all(),
                    'credited_total' => round((float) collect($credits)->sum('total'), 2),
                    // The contracted end, captured BEFORE step 1 overwrote `expiry_date` with the
                    // termination date. It is still derivable from `commencement_date + term_months`,
                    // but a reader of the history should not have to reconstruct it.
                    'contracted_expiry' => $contractedExpiry,
                ],
                $data['document_reference'] ?? null,
            );

            $fresh = $lease->fresh();
            // Surfaced on the model rather than returned, so the existing `terminate(): Lease`
            // contract and its callers are untouched while the UI can still say what it did.
            $fresh->setAttribute('termination_credit_notes', collect($credits));

            return $fresh;
        });
    }
}
