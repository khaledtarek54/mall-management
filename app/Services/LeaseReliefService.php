<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\LeaseEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Grant a lease a temporary, self-reverting rent relief (story LE-03, scenario S6).
 *
 * **What was wrong.** A negotiated six-month concession was entered as a rent change downwards
 * plus a diary note to change it back. The system could not tell a temporary discount from a
 * permanent rent cut, so a forgotten diary meant half rent forever and nothing objected. This is
 * the most convincing argument for the schedule model there is: *the difference between a discount
 * and a rent cut is an end date, and the old model had nowhere to put one.*
 *
 * **The contracted rent does NOT move.** `Lease::base_rent_monthly` is what the lease says the
 * tenant owes; a concession is a temporary departure from it, not a renegotiation of it. So the
 * relief lives entirely in the schedule (what bills) while the contract column keeps saying what
 * was agreed — which is what makes the reversion automatic and what keeps the rent roll honest
 * about the difference.
 *
 * **The marketing levy deliberately does NOT follow the relief**, which is the opposite of what
 * `LeaseRentChangeService` does, and for a reason: the levy is a contractual percentage of
 * contracted base rent, and a concession the landlord grants on rent is not normally a concession
 * on the marketing fund the other tenants are also paying into. A rent CHANGE moves the levy
 * because the contracted rent moved; a rent RELIEF does not because it did not. *(Flagged for
 * Eltizam to confirm against their standard lease wording — if their concessions are gross, this
 * is one line.)*
 */
class LeaseReliefService
{
    public function __construct(private ChargeScheduleService $schedule) {}

    /**
     * @param  array{type?:string, percent_off?:float|null, amount?:float|null, from:string|\DateTimeInterface, to:string|\DateTimeInterface, reason:string, document_reference?:string|null}  $data
     * @return array{relief: list<Charge>, resumed: ?Charge}
     */
    public function grant(Lease $lease, array $data): array
    {
        if (! in_array($lease->status, ['active', 'pending_approval'], true)) {
            throw new InvalidArgumentException(
                "Lease #{$lease->id} is '{$lease->status}'; only active or pending leases can be granted relief."
            );
        }

        $type = $data['type'] ?? 'base_rent';
        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->startOfDay();

        $percentOff = isset($data['percent_off']) ? (float) $data['percent_off'] : null;
        $flatAmount = isset($data['amount']) ? (float) $data['amount'] : null;

        if ($percentOff === null && $flatAmount === null) {
            throw new InvalidArgumentException('Relief needs either a percentage off or a relieved amount.');
        }

        if ($percentOff !== null && ($percentOff <= 0 || $percentOff > 100)) {
            throw new InvalidArgumentException('Relief percentage must be between 0 and 100.');
        }

        if ($flatAmount !== null && $flatAmount < 0) {
            throw new InvalidArgumentException('A relieved amount cannot be negative.');
        }

        // A percentage is resolved PER SEGMENT: "50% off" across a schedule that steps up in
        // October means half of the pre-step rent until then and half of the post-step rent after,
        // which is what the tenant agreed to. A flat amount is flat by definition.
        $amountFor = $percentOff !== null
            ? fn (float $wouldHaveBilled): float => $wouldHaveBilled * (1 - $percentOff / 100)
            : fn (float $wouldHaveBilled): float => $flatAmount;

        return DB::transaction(function () use ($lease, $type, $from, $to, $amountFor, $percentOff, $flatAmount, $data) {
            $result = $this->schedule->overlayWindow($lease, $type, $from, $to, $amountFor, Charge::ORIGIN_MANUAL);

            $firstRelief = $result['relief'][0] ?? null;

            app(RecordLeaseEventService::class)->record(
                $lease,
                LeaseEvent::TYPE_ABATEMENT,
                ChargeScheduleService::billingBoundary($from),
                $data['reason'],
                [
                    'charge_type' => $type,
                    'window_from' => ChargeScheduleService::billingBoundary($from)->toDateString(),
                    'window_to' => $to->endOfMonth()->toDateString(),
                    'percent_off' => $percentOff,
                    'flat_amount' => $flatAmount,
                    'amount_from' => $firstRelief ? (float) $lease->base_rent_monthly : null,
                    'amount_to' => $firstRelief ? (float) $firstRelief->amount : null,
                    'reverts_to' => $result['resumed'] ? (float) $result['resumed']->amount : null,
                    'rows_opened' => collect($result['relief'])->map(fn (Charge $c) => [
                        'id' => $c->id,
                        'amount' => (float) $c->amount,
                        'start_date' => $c->start_date?->toDateString(),
                        'end_date' => $c->end_date?->toDateString(),
                    ])->all(),
                ],
                $data['document_reference'] ?? null,
            );

            return $result;
        });
    }
}
