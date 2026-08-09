<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\Unit;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Add or give back space on a live lease, effective on a date (story LE-02, scenario S5).
 *
 * **What was wrong.** Expanding meant adding a `lease_unit` row and then running "Change Rent" to
 * type a blended figure. Neither carried a date, so the extra area counted from whenever somebody
 * clicked save, the rent changed on the same accident, and nothing recorded that this was an
 * *expansion* rather than a renegotiation. Contraction was worse: giving space back meant deleting
 * the pivot row, erasing the fact that the tenant had ever held it — so the next recovery
 * reconciliation re-apportioned the whole year as if the unit had never been theirs.
 *
 * Now the premises are date-ranged like the rent: an expansion OPENS a pivot row on its effective
 * date, a contraction CLOSES one the day before it takes effect, and the row stays. Rent moves on
 * the same date through the same schedule writer, so the money and the space can never disagree
 * about when the deal changed.
 *
 * **The master unit cannot be given back.** It is the lease's identity — `leases.unit_id` points at
 * it, occupancy and every single-unit code path read it. A tenant relocating out of their master
 * unit is a *relocation*, a different deal with a different event type, and pretending it is a
 * contraction would leave a lease pointing at space it no longer holds.
 */
class LeaseSpaceChangeService
{
    public function __construct(private ChargeScheduleService $schedule) {}

    /**
     * @param  array{unit_ids?:array<int>, effective_from:string|\DateTimeInterface, rent_increment?:float|null, new_total_rent?:float|null, reason:string, document_reference?:string|null}  $data
     */
    public function expand(Lease $lease, array $data): Lease
    {
        $this->assertChangeable($lease);

        $effectiveFrom = ChargeScheduleService::billingBoundary(CarbonImmutable::parse($data['effective_from']));
        $unitIds = array_values(array_unique(array_map('intval', $data['unit_ids'] ?? [])));

        if ($unitIds === []) {
            throw new InvalidArgumentException('An expansion needs at least one unit.');
        }

        $units = Unit::query()->whereIn('id', $unitIds)->get();

        if ($units->count() !== count($unitIds)) {
            throw new InvalidArgumentException('One or more of those units does not exist.');
        }

        // Same property. A lease spanning two malls is not an expansion, it is a data-entry
        // accident that would corrupt occupancy, the CAM denominator and property isolation at
        // once.
        $leaseAssetId = $lease->unit?->asset_id;
        foreach ($units as $unit) {
            if ($leaseAssetId !== null && (int) $unit->asset_id !== (int) $leaseAssetId) {
                throw new InvalidArgumentException("Unit {$unit->code} belongs to a different property.");
            }

            if ($lease->unitsOn($effectiveFrom)->contains('id', $unit->id)) {
                throw new InvalidArgumentException("Unit {$unit->code} is already on this lease.");
            }

            if ($unit->isActivelyLeased($lease->id)) {
                throw new InvalidArgumentException("Unit {$unit->code} is already let to someone else.");
            }
        }

        return DB::transaction(function () use ($lease, $units, $effectiveFrom, $data) {
            $areaBefore = $lease->totalAreaSqmOn($effectiveFrom);

            foreach ($units as $unit) {
                $lease->units()->attach($unit->id, [
                    'is_master' => false,
                    'effective_from' => $effectiveFrom->toDateString(),
                    'effective_to' => null,
                ]);
            }

            $lease->load('units');
            $areaAfter = $lease->totalAreaSqmOn($effectiveFrom);

            $rent = $this->applyRentChange($lease, $data, $effectiveFrom);

            $units->each->recomputeStatus();

            app(RecordLeaseEventService::class)->record(
                $lease,
                LeaseEvent::TYPE_EXPANSION,
                $effectiveFrom,
                $data['reason'],
                [
                    'charge_type' => 'base_rent',
                    'units_added' => $units->pluck('code')->all(),
                    'area_from' => $areaBefore,
                    'area_to' => $areaAfter,
                    'amount_from' => $rent['from'],
                    'amount_to' => $rent['to'],
                ],
                $data['document_reference'] ?? null,
            );

            return $lease->fresh();
        });
    }

    /**
     * @param  array{unit_ids?:array<int>, effective_from:string|\DateTimeInterface, rent_decrement?:float|null, new_total_rent?:float|null, reason:string, document_reference?:string|null}  $data
     */
    public function contract(Lease $lease, array $data): Lease
    {
        $this->assertChangeable($lease);

        $effectiveFrom = ChargeScheduleService::billingBoundary(CarbonImmutable::parse($data['effective_from']));
        $unitIds = array_values(array_unique(array_map('intval', $data['unit_ids'] ?? [])));

        if ($unitIds === []) {
            throw new InvalidArgumentException('A contraction needs at least one unit.');
        }

        $held = $lease->unitsOn($effectiveFrom->subDay());

        foreach ($unitIds as $id) {
            $unit = $held->firstWhere('id', $id);

            if ($unit === null) {
                throw new InvalidArgumentException('That unit is not on this lease on the effective date.');
            }

            if ((int) $lease->unit_id === (int) $id) {
                throw new InvalidArgumentException(
                    "Unit {$unit->code} is this lease's master unit and cannot be given back — record a relocation instead."
                );
            }
        }

        if ($held->count() === count($unitIds)) {
            throw new InvalidArgumentException('A lease cannot give back all of its space — terminate it instead.');
        }

        return DB::transaction(function () use ($lease, $unitIds, $held, $effectiveFrom, $data) {
            $areaBefore = $lease->totalAreaSqmOn($effectiveFrom->subDay());
            $codes = $held->whereIn('id', $unitIds)->pluck('code')->all();

            foreach ($unitIds as $id) {
                // CLOSE the row, never delete it: the tenant DID hold this space, and a recovery
                // reconciliation for the year must still apportion the months they had it.
                $lease->units()->updateExistingPivot($id, [
                    'effective_to' => $effectiveFrom->subDay()->toDateString(),
                ]);
            }

            $lease->load('units');
            $areaAfter = $lease->totalAreaSqmOn($effectiveFrom);

            $rent = $this->applyRentChange($lease, $data, $effectiveFrom, decrement: true);

            Unit::whereIn('id', $unitIds)->get()->each->recomputeStatus();

            app(RecordLeaseEventService::class)->record(
                $lease,
                LeaseEvent::TYPE_CONTRACTION,
                $effectiveFrom,
                $data['reason'],
                [
                    'charge_type' => 'base_rent',
                    'units_removed' => $codes,
                    'area_from' => $areaBefore,
                    'area_to' => $areaAfter,
                    'amount_from' => $rent['from'],
                    'amount_to' => $rent['to'],
                ],
                $data['document_reference'] ?? null,
            );

            return $lease->fresh();
        });
    }

    private function assertChangeable(Lease $lease): void
    {
        if (! in_array($lease->status, ['active', 'pending_approval'], true)) {
            throw new InvalidArgumentException(
                "Lease #{$lease->id} is '{$lease->status}'; only active or pending leases can change their premises."
            );
        }
    }

    /**
     * Move the rent on the SAME effective date as the space, through the same schedule writer.
     *
     * The caller may state the new total or the increment; an expansion with no rent change at all
     * is legitimate (space handed over rent-free during fit-out, with the money following later).
     *
     * @param  array<string, mixed>  $data
     * @return array{from: float|null, to: float|null}
     */
    private function applyRentChange(Lease $lease, array $data, CarbonImmutable $effectiveFrom, bool $decrement = false): array
    {
        $current = (float) $lease->base_rent_monthly;

        $newTotal = match (true) {
            isset($data['new_total_rent']) => (float) $data['new_total_rent'],
            isset($data['rent_increment']) => $current + (float) $data['rent_increment'],
            isset($data['rent_decrement']) => $current - (float) $data['rent_decrement'],
            // A lease priced per m² re-prices ITSELF when the area moves (story LS-04) — that is
            // the whole reason to hold a rate rather than an amount. Derived at the effective date,
            // so it reflects the premises as they stand from that day and not as they stand now.
            default => $lease->deriveBaseRentFromRate($effectiveFrom),
        };

        if ($newTotal === null) {
            return ['from' => null, 'to' => null];
        }

        if ($newTotal < 0) {
            throw new InvalidArgumentException('The resulting rent cannot be negative.');
        }

        $this->schedule->setAmount($lease, 'base_rent', $newTotal, $effectiveFrom, [
            'name' => 'Base Rent',
            'vat_applicable' => false,
            'vat_rate' => Vat::EXEMPT,
        ], Charge::ORIGIN_MANUAL);

        // The contracted rent really did change — this is a renegotiation of the premises, not a
        // temporary concession, so unlike a relief the lease column moves with the schedule.
        $lease->update(['base_rent_monthly' => $newTotal]);

        // The marketing levy is a percentage of base rent and must move on the same date, or a
        // past month would bill the historically-correct rent beside a levy from today's.
        if ($newTotal > 0 && $lease->has_marketing_levy) {
            app(MarketingLevyService::class)->createLevyCharge($lease->fresh(), $effectiveFrom);
        }

        return ['from' => $current, 'to' => $newTotal];
    }
}
