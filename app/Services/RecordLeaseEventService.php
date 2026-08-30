<?php

namespace App\Services;

use App\Support\LeaseEventNarrative;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\LeaseEvent;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Write one entry in a lease's history (story LE-01).
 *
 * **The one place events are created.** Every commercial change — a rent modification, a relief, a
 * holdover conversion, an expansion — calls this, inside the same transaction as the change itself.
 * That last part is the point: an event written outside the transaction can survive a rolled-back
 * change (a history entry for something that never happened) or be lost while the change commits (a
 * change with no history), and both are worse than not having the table.
 *
 * **The actor is read from the session, not passed in.** A sweep running under `artisan` has no
 * authenticated user, so `auth()->id()` is null there and the timeline says "System" — which is
 * true. Letting each caller supply an actor would eventually put a human's name against an
 * automated escalation, and an audit trail that lies is worse than one that admits ignorance.
 */
class RecordLeaseEventService
{
    /**
     * @param  array<string, mixed>  $payload  what moved — before/after amounts, rows opened/closed
     */
    public function record(
        Lease $lease,
        string $type,
        CarbonImmutable $effectiveDate,
        // NULLABLE since the narratives moved into the payload: a service that composes its
        // sentence at read time has no prose to pass, and an empty string would be indistinguishable
        // from an operator who typed nothing.
        ?string $reason,
        array $payload = [],
        ?string $documentReference = null,
        ?int $userId = null,
    ): LeaseEvent {
        if (! in_array($type, LeaseEvent::TYPES, true)) {
            throw new InvalidArgumentException("'{$type}' is not a lease event type.");
        }

        $reason = trim((string) $reason);

        // A row that says only "something changed" is the failure this table exists to prevent —
        // that is what the activity log already gives. So an event must be EXPLICABLE, and there
        // are now two ways to be: the operator typed a reason, or the service stamped a narrative
        // key the reader composes from (see LeaseEventNarrative — a row stores data, not prose).
        //
        // Checking for prose alone would refuse every service that moved to a key, which is what
        // the first version of that change hit; checking for neither would let an unexplained row
        // through, which is the original defect. Both, or refuse.
        if ($reason === '' && blank($payload[LeaseEventNarrative::KEY] ?? null)) {
            throw new InvalidArgumentException('A lease event needs a reason or a narrative key — that is the point of recording it.');
        }

        return LeaseEvent::create([
            'lease_id' => $lease->id,
            'type' => $type,
            'effective_date' => $effectiveDate->toDateString(),
            // NULL, not '' — a reader tells "the operator said nothing" from "the operator typed
            // an empty box" by asking `filled()`, and an empty string answers that question wrong.
            'reason' => $reason !== '' ? $reason : null,
            'document_reference' => $documentReference !== null && trim($documentReference) !== ''
                ? trim($documentReference)
                : null,
            'user_id' => $userId ?? auth()->id(),
            'payload' => $payload ?: null,
        ]);
    }

    /**
     * The payload shape for a change to one charge type — used by rent modifications, reliefs and
     * holdover conversions alike so the timeline can render them without knowing which produced it.
     *
     * @param  iterable<Charge>  $opened
     * @return array<string, mixed>
     */
    public static function scheduleChangePayload(
        string $chargeType,
        ?float $amountFrom,
        ?float $amountTo,
        iterable $opened = [],
        string $narrative = 'rent_changed',
        array $narrativeData = [],
    ): array {
        return array_filter([
            // The narrative rides with the figures it describes, so both callers of this builder
            // get it from one place — see LeaseEventNarrative for why a row stores a key.
            LeaseEventNarrative::KEY => $narrative,
            // Whatever else that particular sentence quotes — the escalation step, the raw index
            // reading behind a collared one. It goes in the PAYLOAD beside the figures rather than
            // into a formatted string, so it is queryable and reads in the reader's language.
            ...$narrativeData,
            'charge_type' => $chargeType,
            'amount_from' => $amountFrom,
            'amount_to' => $amountTo,
            'rows_opened' => collect($opened)->filter()->map(fn ($charge) => [
                'id' => $charge->id,
                'amount' => (float) $charge->amount,
                'start_date' => $charge->start_date?->toDateString(),
                'end_date' => $charge->end_date?->toDateString(),
            ])->values()->all() ?: null,
        ], fn ($v) => $v !== null);
    }
}
