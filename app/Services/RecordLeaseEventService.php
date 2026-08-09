<?php

namespace App\Services;

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
        string $reason,
        array $payload = [],
        ?string $documentReference = null,
        ?int $userId = null,
    ): LeaseEvent {
        if (! in_array($type, LeaseEvent::TYPES, true)) {
            throw new InvalidArgumentException("'{$type}' is not a lease event type.");
        }

        $reason = trim($reason);

        // A blank reason is the failure mode this table exists to prevent: rows that record that
        // *something* changed, which is what the activity log already says. Refuse loudly at the
        // service so the gap shows up in development rather than as an empty timeline in year two.
        if ($reason === '') {
            throw new InvalidArgumentException('A lease event needs a reason — that is the point of recording it.');
        }

        return LeaseEvent::create([
            'lease_id' => $lease->id,
            'type' => $type,
            'effective_date' => $effectiveDate->toDateString(),
            'reason' => $reason,
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
     * @param  iterable<\App\Models\Charge>  $opened
     * @return array<string, mixed>
     */
    public static function scheduleChangePayload(
        string $chargeType,
        ?float $amountFrom,
        ?float $amountTo,
        iterable $opened = [],
    ): array {
        return array_filter([
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
