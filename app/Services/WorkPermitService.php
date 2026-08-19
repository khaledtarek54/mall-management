<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkPermit;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Issue, close and cancel a permit to work.
 *
 * The three acts are a service rather than form writes for the reason every other controlled
 * transition here is: issuing is the moment the operator accepts a risk, and it has to refuse the
 * same way whether it arrives from a screen, the console or an import.
 */
class WorkPermitService
{
    /**
     * Authorise the work.
     *
     * **The vendor must be dispatchable.** A contractor whose insurance has lapsed, or who has been
     * blacklisted, is precisely who a permit must not be issued to — and `Vendor::isDispatchable()`
     * already encodes that (active, and no expired blocking document). Reusing it rather than
     * re-testing the conditions means a permit cannot become the one door left open after the work
     * order path was closed: `FacilityWorkOrder::saving` refuses to dispatch such a vendor, and a
     * permit issued to them would be the same hazard with a signature on it.
     *
     * A permit with no registered vendor is allowed — a named individual, a tenant's own fitter —
     * and then the contractor's name is the record.
     */
    public function issue(WorkPermit $permit, ?User $actor = null): WorkPermit
    {
        $actor ??= auth()->user();

        if ($permit->status !== WorkPermit::STATUS_DRAFT) {
            throw new DomainException(__('admin.errors.work_permit_not_draft'));
        }

        if (blank($permit->conditions)) {
            // A permit is the CONDITIONS. Issued with none it is a note saying work happened, which
            // is the form-shaped version of this control and worth refusing rather than allowing
            // people to discover later that the field was optional.
            throw new DomainException(__('admin.errors.work_permit_needs_conditions'));
        }

        $vendor = $permit->vendor_id !== null ? Vendor::find($permit->vendor_id) : null;

        if ($vendor instanceof Vendor && ! $vendor->isDispatchable()) {
            throw new DomainException(__('admin.errors.work_permit_vendor_not_dispatchable', [
                'vendor' => $vendor->name,
            ]));
        }

        return DB::transaction(function () use ($permit, $actor) {
            $permit->update([
                'status' => WorkPermit::STATUS_ISSUED,
                'issued_by_user_id' => $actor?->id,
                'issued_at' => now(),
            ]);

            return $permit->refresh();
        });
    }

    /**
     * Close it out — the record that the work stopped and the area was left safe.
     *
     * **A reason is required.** "Closed" with nothing written is indistinguishable from a permit
     * somebody tidied off a list, and the whole value of a closure is that a named person states
     * what they checked. The same argument that makes a rejection reason mandatory on a fit-out
     * permit decision.
     *
     * Closing LATE is deliberately allowed. A permit whose window passed unclosed is a finding, not
     * a locked door — refusing the closure would leave the register permanently wrong about a job
     * that did in fact finish safely, and would push people to cancel it instead, destroying the
     * distinction between "closed late" and "never happened".
     */
    public function close(WorkPermit $permit, string $notes, ?User $actor = null): WorkPermit
    {
        $actor ??= auth()->user();

        if ($permit->status !== WorkPermit::STATUS_ISSUED) {
            throw new DomainException(__('admin.errors.work_permit_not_issued'));
        }

        if (trim($notes) === '') {
            throw new DomainException(__('admin.errors.work_permit_needs_closure_notes'));
        }

        return DB::transaction(function () use ($permit, $notes, $actor) {
            $permit->update([
                'status' => WorkPermit::STATUS_CLOSED,
                'closed_by_user_id' => $actor?->id,
                'closed_at' => now(),
                'closure_notes' => $notes,
            ]);

            return $permit->refresh();
        });
    }

    /**
     * Withdraw a permit — the work is not happening, or authorisation is revoked.
     *
     * Distinct from closing: closing says the work finished and the area is safe, cancelling says
     * it did not proceed under this authorisation. Collapsing the two would make the register
     * unable to answer the only question an auditor asks of it.
     */
    public function cancel(WorkPermit $permit, string $reason, ?User $actor = null): WorkPermit
    {
        if (in_array($permit->status, WorkPermit::TERMINAL, true)) {
            throw new DomainException(__('admin.errors.work_permit_terminal'));
        }

        if (trim($reason) === '') {
            throw new DomainException(__('admin.errors.work_permit_needs_cancel_reason'));
        }

        return DB::transaction(function () use ($permit, $reason, $actor) {
            $permit->update([
                'status' => WorkPermit::STATUS_CANCELLED,
                'closed_by_user_id' => $actor?->id ?? auth()->id(),
                'closed_at' => now(),
                'closure_notes' => $reason,
            ]);

            return $permit->refresh();
        });
    }
}
