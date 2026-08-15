<?php

namespace App\Services;

use App\Models\FacilityWorkOrder;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Who caused the failure, and who therefore bears the cost (FR-CM-12, FR-CM-13).
 *
 * The FRD, verbatim:
 *   FR-CM-12 — "For parts sourced from outside, the system shall **determine responsibility** (and
 *               who bears the cost) based on **who caused the part to fail, as recorded on the
 *               work order**."
 *   FR-CM-13 — "The system shall **record** whether the mall or the tenant is financially
 *               responsible for a repair, based on who caused the damage."
 *
 * **This service records a finding. It does not bill anyone.** The FRD's verbs are *determine* and
 * *record*; no requirement in it asks the system to invoice a tenant, and its own "Open Items" list
 * never raises it. The recharge seam is deliberately absent — see the class footer.
 */
class AttributeWorkOrderFaultService
{
    /**
     * Asserting that a *tenant* owes money for a repair is a commercial claim, not a technical
     * observation. The engineer records what they found; a manager rules on who pays — the same
     * second-pair-of-eyes principle as a part-draw approval (FR-CM-10). Withheld from `operations`
     * in the seeder for exactly that reason.
     */
    public const PERMISSION = 'facility.attribute_fault';

    /**
     * Record the cause and derive the bearer.
     *
     * Idempotent in the sense that matters: re-stating the same finding is harmless, and every
     * change is logged with who/when/why. Not locked after the first write — a cause is often
     * revised once the engineer actually opens the machine, and freezing the first guess would
     * make the record *less* true. The audit trail is the control, not immutability.
     *
     * @throws DomainException|InvalidArgumentException
     */
    public function attribute(
        FacilityWorkOrder $order,
        string $faultParty,
        ?string $notes = null,
        ?string $bearerOverride = null,
        ?User $actor = null,
    ): FacilityWorkOrder {
        $actor ??= auth()->user();

        if (! in_array($faultParty, FacilityWorkOrder::FAULT_PARTIES, true)) {
            throw new InvalidArgumentException(
                "Unknown fault party '{$faultParty}'; expected one of: ".implode(', ', FacilityWorkOrder::FAULT_PARTIES).'.'
            );
        }

        return DB::transaction(function () use ($order, $faultParty, $notes, $bearerOverride, $actor) {
            /** @var FacilityWorkOrder $locked */
            $locked = FacilityWorkOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $this->assertMayAttribute($actor);

            // A cancelled job never happened, so there is no cost and nothing to apportion.
            // `done` is deliberately ALLOWED: terminal immutability protects the record of the
            // *work*, and the cause is usually only known once the work is finished. Refusing to
            // record it on a closed job would mean the finding could never be recorded at all —
            // and FR-CM-12 explicitly wants it "as recorded on the work order".
            if ($locked->status === 'cancelled') {
                throw new DomainException(__('admin.facility.errors.fault_cancelled'));
            }

            // FR-CM-13: derived from the cause, not typed in beside it.
            $bearer = FacilityWorkOrder::bearerFor($faultParty);

            if ($bearerOverride !== null && $bearerOverride !== $bearer) {
                // An override is legitimate (a lease may make the tenant liable for wear on their
                // own fit-out), but it is the operator overruling the FRD's own derivation, so it
                // must be argued for rather than clicked.
                if (! in_array($bearerOverride, FacilityWorkOrder::COST_BEARERS, true)) {
                    throw new InvalidArgumentException("Unknown cost bearer '{$bearerOverride}'.");
                }

                if (blank($notes)) {
                    throw new DomainException(__('admin.facility.errors.fault_override_needs_reason'));
                }

                $bearer = $bearerOverride;
            }

            // You cannot make "the tenant" liable where there is no tenant. A work order carries a
            // NULLABLE unit_id — a common-area chiller has no occupier — so this is reachable
            // whenever someone attributes a common-area job to a tenant, and it is exactly the
            // case that would later produce an invoice addressed to nobody.
            if ($bearer === FacilityWorkOrder::BEARER_TENANT && $locked->bearingTenant() === null) {
                throw new DomainException(__('admin.facility.errors.fault_no_tenant'));
            }

            $locked->update([
                'fault_party' => $faultParty,
                'cost_bearer' => $bearer,
                'fault_notes' => $notes,
                'fault_recorded_by_user_id' => $actor->id,
                'fault_recorded_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    /** @throws DomainException */
    private function assertMayAttribute(?User $actor): void
    {
        if ($actor === null || ! $actor->can(self::PERMISSION)) {
            throw new DomainException(__('admin.facility.errors.fault_denied'));
        }
    }

    /*
     * ---------------------------------------------------------------------------------------
     * NOT BUILT ON PURPOSE — the recharge seam (beyond the FRD).
     *
     * The obvious next step is "cost_bearer = tenant → invoice them". The FRD does not ask for it:
     * FR-CM-12/13 say *determine* and *record*, and nothing in the document asks the system to
     * bill a tenant for a repair. Khaled confirmed record-only (2026-07-16).
     *
     * When the client does ask, this is the seam, and these are the questions that must be
     * answered FIRST (BUSINESS-RULES open question 14):
     *   - Is a recharge VATable (14% on services), or a cost recovery outside VAT?
     *   - What is the recoverable amount — parts only, or parts + labour + the vendor's invoice?
     *     Note `partsCost()` can already exceed what the GL knows about a job, because an
     *     externally-bought part posts nothing (its accounting document is the vendor bill).
     *     Billing a tenant off partsCost() would bill them for money the books never saw.
     *   - What happens when the cost changes after the recharge (a part draw voided, an external
     *     record removed)? A tenant billed for a part that came back is the failure mode here.
     *   - Does the tenant get to dispute it, and what does that do to the invoice?
     *
     * Whoever builds it: raise it through the shipped ad-hoc-invoice pattern (CamReconciliation /
     * percentage-rent overage), never by writing `paid_amount`/`balance` — `Invoice::
     * recomputeTotals()` is the single source of truth.
     * ---------------------------------------------------------------------------------------
     */
}
