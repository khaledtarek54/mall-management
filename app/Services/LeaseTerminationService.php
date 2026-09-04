<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Support\LeaseEventNarrative;
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
     * @param  array{termination_date:string|\DateTimeInterface|null, reason:string|null, cancel_open_invoices?:bool, credit_unearned?:bool, bill_final_period?:bool}  $data
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
        // Defaults ON, like `credit_unearned` and for the mirror-image reason: the two halves of
        // ending a tenancy fairly are giving back what was not earned and asking for what was. The
        // opt-out exists because both write documents that a closed period can refuse.
        $billFinalPeriod = (bool) ($data['bill_final_period'] ?? true);

        return DB::transaction(function () use ($lease, $terminationDate, $reason, $cancelOpenInvoices, $creditUnearned, $billFinalPeriod) {
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

            // NO note is appended. This used to write `Terminated on 2026-08-30: <reason>` into
            // `leases.notes` — raw English, frozen for ever, read by nothing, and duplicating the
            // lease event recorded below. Module 04 already records the decision that the event
            // replaced the notes-append (*"unqueryable, unreportable, unattributable, and it
            // polluted a field operators use for their own notes"*); this was the one call site
            // where the old mechanism survived beside the new one.
            $lease->update([
                // `expiry_date` moves either way — that IS when the tenancy ends, and it is what
                // `leases:expire` and every projection read.
                'status' => $underNotice ? $lease->status : 'terminated',
                'expiry_date' => $terminationDate,
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
            //
            // DEFERRED to the end of the termination (SW-050). The planner skips an inactive row,
            // so deactivating here made the schedule unreadable before the final consumed period
            // could be billed off it — `planInvoiceForLease()` answered `no_applicable_charges`.
            // The row's own stated cause was this line, and it is half right: it is not what stops
            // the SCHEDULED run (the status refusal fires first, so forcing the flag back on changes
            // nothing there), but it is exactly what stops an act that has already got past the
            // status. Setting `end_date` above is what bounds the billing; the flag only says the
            // row is finished, so it can be set once nothing else needs to read it.

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
                    // Nor one carrying a WRITE-OFF, for the same reason and with the same shape as
                    // the ETA clause above (SW-023). A write-off posts `Dr bad_debt / Cr AR` against
                    // a row this cancel does not touch, so cancelling on top of it leaves the loss
                    // standing against a document that no longer exists — and a partially
                    // written-off invoice matches every other clause here, precisely BECAUSE a
                    // write-off leaves `balance` standing and is not a settlement channel, so
                    // `paid_amount` stays 0.
                    //
                    // EXCLUDED here rather than left to the model guard: `Invoice::updating` refuses
                    // it on every path, which is the backstop, but this loop has no per-row catch —
                    // one such invoice would abort the whole termination and leave the lease
                    // un-terminatable. The invoice stays open and visible in AR, which is the honest
                    // state; the operator reverses the write-off and voids it deliberately.
                    ->whereDoesntHave('writeOffs')
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

            // ── AND ASK FOR THE PERIOD THE TENANT ACTUALLY CONSUMED (SW-050) ────────────────────
            //
            // The mirror of the credit above, and it was missing. A charge billed IN ARREARS is
            // invoiced one cycle behind, so the days between the last invoice and the termination
            // are billed by NOTHING once the lease ends — and `MoveOutStatementService` nets arrears
            // off the deposit from EXISTING invoices only, so an invoice nobody raised is not open
            // AR and the refund cheque is larger by exactly that amount. Money the tenant owed,
            // leaving the building, with nothing on the statement to say so.
            //
            // Runs AFTER the credit note deliberately: the credit reverses what was billed in
            // advance and never earned, this asks for what was earned and never billed. They touch
            // different rows and neither reads the other, but ordering them this way keeps the
            // statement's story chronological for anyone reading it later.
            //
            // Under NOTICE it does not run — the lease is still active, the schedule is still live,
            // and the ordinary billing run will raise the final invoice when the month turns. Only a
            // lease that has actually ENDED has a consumed period nobody will bill.
            $finalBill = null;

            if ($billFinalPeriod && ! $underNotice) {
                $result = app(BillFinalPeriodService::class)->billFor($lease->fresh(), $terminationDate);
                $finalBill = $result['invoice'] ?? null;
            }

            // Now nothing else reads the schedule — see step 3.
            if (! $underNotice) {
                Charge::where('lease_id', $lease->id)->update(['is_active' => false]);
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
                    // The other half of the money story, beside the credits: what was ASKED for on
                    // the way out. `$finalBill` was assigned and never read for one commit, which is
                    // how a fact nobody records becomes a fact nobody can reconstruct.
                    'final_invoice' => $finalBill?->number,
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
