<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Support\LeaseEventNarrative;
use App\Support\LeaseTerm;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * **Extend a running lease's term — the last commercial change that had no act behind it.**
 *
 * `expiry_date` and `term_months` were free text on the edit form, so a term extension happened by
 * typing a date: no reason, no actor, no event, and nothing downstream able to tell an extension
 * from a correction. `LeaseEvent::TYPE_EXTENSION` had existed unused the whole time — the type was
 * declared and nothing ever wrote one.
 *
 * **An extension is not a renewal, and the distinction is the point.** A renewal ENDS this tenancy
 * and starts a new lease with its own reference, its own negotiated terms and its own document; the
 * chain is `previous_lease_id`. An extension leaves the SAME contract running for longer on the same
 * terms — which is what a "further term" or an exercised extension option produces. Modelling one as
 * the other loses the fact of which happened, and Yardi keeps them apart for the same reason.
 *
 * **What it deliberately does NOT touch:** the charge schedule. Rent rows are open-ended, so a
 * longer term simply keeps billing them — there is nothing to re-date, and re-dating would be the
 * bug. What it DOES do is re-project the escalation ladder, because anniversaries that fell outside
 * the old term now fall inside the new one and a lease must not run for two more years with its
 * future rent recorded nowhere.
 */
class LeaseExtensionService
{
    public function __construct(private readonly ChargeScheduleService $schedule) {}

    /**
     * @param  array{new_expiry_date: string|\DateTimeInterface, reason: string, document_reference?: string|null}  $data
     */
    public function extend(Lease $lease, array $data): Lease
    {
        if (! in_array($lease->status, ['active', 'pending_approval'], true)) {
            throw new DomainException(__('admin.validation.extension_requires_active_lease'));
        }

        if (blank($lease->commencement_date) || blank($lease->expiry_date)) {
            throw new DomainException(__('admin.validation.extension_needs_a_term'));
        }

        $current = CarbonImmutable::instance($lease->expiry_date);
        $new = CarbonImmutable::parse($data['new_expiry_date'])->startOfDay();

        // Only forwards. Pulling an expiry backwards ends a tenancy early — that is a TERMINATION,
        // which settles the deposit, credits unearned billing and closes the charge schedule. Letting
        // it happen here would end a lease with none of that, and call it an extension in the record.
        if (! $new->greaterThan($current)) {
            throw new DomainException(__('admin.validation.extension_must_move_forward', [
                'current' => $current->format('d/m/Y'),
            ]));
        }

        return DB::transaction(function () use ($lease, $data, $current, $new) {
            // `monthsSpanning`, not `monthsBetween`: a further term is routinely negotiated to a
            // date rather than a round number of months (a financial year end, the neighbour's
            // fit-out), and `term_months` is NOT NULL — so it takes the whole months the new range
            // covers rather than refusing a term that is not a tidy multiple.
            $months = LeaseTerm::monthsSpanning($lease->commencement_date, $new);

            $lease->update([
                'expiry_date' => $new->toDateString(),
                'term_months' => $months ?? $lease->term_months,
            ]);

            // The history entry, INSIDE the transaction — the change and its record commit or fail
            // together, as every other lease event does. The actor comes from the session; a
            // programmatic call records "System", which is true.
            app(RecordLeaseEventService::class)->record(
                lease: $lease,
                type: LeaseEvent::TYPE_EXTENSION,
                effectiveDate: $current->addDay(),   // the day the further term begins
                reason: $data['reason'],
                payload: [
                    LeaseEventNarrative::KEY => 'term_extended',
                    'previous_expiry_date' => $current->toDateString(),
                    'new_expiry_date' => $new->toDateString(),
                    'previous_term_months' => (int) $lease->getOriginal('term_months'),
                    'new_term_months' => (int) $lease->term_months,
                ],
                documentReference: $data['document_reference'] ?? null,
            );

            // Anniversaries that fell past the OLD expiry now fall inside the term. Idempotent by
            // construction: `setAmount()` writes only where the amount is not already in force, so
            // the steps already projected for the original term are recomputed to the same figures
            // and no-op, and only the new years produce rows.
            $this->schedule->projectTermEscalations($lease->fresh());

            return $lease->fresh();
        });
    }
}
