<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Let a parking bay, store or signage face to a lease — and bill it (space model, RC of story 09).
 *
 * **It writes an ordinary charge row.** The assignment itself moves no money; what bills is a
 * `parking` charge on the lease's schedule, which the monthly run, VAT and the GL already
 * understand. There is no second billing engine here, which is the whole reason rentable items were
 * built on the existing charge schedule rather than beside it.
 *
 * **One charge row per lease, not per item.** A lease with four bays has ONE `parking` charge whose
 * amount is the sum of what it pays for the items it holds on that date. That is forced rather than
 * chosen: `Charge` refuses two active rows of the same type covering the same period (the overlap
 * guard), and it is also what an operator wants to read on an invoice — "Parking (4 bays)", not four
 * near-identical lines.
 *
 * **Dated, like the premises.** An item taken on 1 March bills from March; released at the end of
 * June, it bills through June and stops in July. The re-derivation runs at the effective date and
 * goes through `ChargeScheduleService`, so the old amount stays true for the months it was true for.
 */
class AssignRentableItemService
{
    public function __construct(private ChargeScheduleService $schedule) {}

    /**
     * Assign an item to a lease from a date.
     *
     * @param  array{effective_from?: string|\DateTimeInterface|null, monthly_rate?: float|null}  $data
     */
    public function assign(Lease $lease, RentableItem $item, array $data = []): void
    {
        $from = ChargeScheduleService::billingBoundary(
            isset($data['effective_from']) && $data['effective_from']
                ? CarbonImmutable::parse($data['effective_from'])
                : CarbonImmutable::now(),
        );

        if (! in_array($lease->status, ['active', 'pending_approval'], true)) {
            throw new DomainException(__('admin.errors.rentable_item_lease_not_active'));
        }

        // A bay in another mall cannot be let by this lease. The lease's property comes from its
        // unit, the same derivation every other property check here uses.
        if ((int) $item->asset_id !== (int) $lease->unit?->asset_id) {
            throw new DomainException(__('admin.errors.rentable_item_other_property'));
        }

        if ($item->status === RentableItem::STATUS_OUT_OF_SERVICE) {
            throw new DomainException(__('admin.errors.rentable_item_out_of_service'));
        }

        // The same double-booking rule the premises have. Lock the CONTENDED item, not the lease:
        // two operators assigning the same bay to different tenants contend on the item row, and
        // locking the lease would let both through.
        DB::transaction(function () use ($lease, $item, $from, $data) {
            $locked = RentableItem::query()->lockForUpdate()->findOrFail($item->id);

            // NOT `ignoreLeaseId: $lease->id`. That exclusion was meant for "somebody else has it"
            // and silently permitted the same lease to take the same bay TWICE — the pivot's unique
            // key is (lease, item, effective_from), so a second assignment on a different date is
            // accepted, both rows read as held, and `rebuildCharge()` sums the bay twice. A
            // double-click or an operator correcting a date doubled the tenant's parking bill with
            // nothing to show for it. Re-letting after a release still works: `effective_to` is set
            // by then, so `isHeldOn` is false.
            if ($locked->isHeldOn($from)) {
                throw new DomainException(__(
                    $lease->rentableItems()->whereKey($locked->id)->wherePivotNull('effective_to')->exists()
                        ? 'admin.errors.rentable_item_already_on_this_lease'
                        : 'admin.errors.rentable_item_already_held',
                    ['code' => $locked->code],
                ));
            }

            $rate = isset($data['monthly_rate'])
                ? round((float) $data['monthly_rate'], 2)
                : (float) $locked->monthly_rate;

            if ($rate < 0) {
                throw new DomainException(__('admin.errors.rentable_item_negative_rate'));
            }

            $lease->rentableItems()->attach($locked->id, [
                'effective_from' => $from->toDateString(),
                'effective_to' => null,
                'monthly_rate' => $rate,
            ]);

            $locked->update(['status' => RentableItem::STATUS_ASSIGNED]);

            $this->rebuildCharge($lease->fresh(), $from);
        });
    }

    /** Give an item back, effective at the end of a date. */
    public function release(Lease $lease, RentableItem $item, mixed $effectiveTo = null): void
    {
        $to = $effectiveTo
            ? CarbonImmutable::parse($effectiveTo)->startOfDay()
            : CarbonImmutable::now()->endOfMonth()->startOfDay();

        DB::transaction(function () use ($lease, $item, $to) {
            $held = $lease->rentableItems()
                ->wherePivot('rentable_item_id', $item->id)
                ->wherePivotNull('effective_to')
                ->first();

            if (! $held) {
                throw new DomainException(__('admin.errors.rentable_item_not_held'));
            }

            $lease->rentableItems()->updateExistingPivot($item->id, [
                'effective_to' => $to->toDateString(),
            ]);

            // Free for re-letting only once nobody else holds it the day after.
            if (! $item->fresh()->isHeldOn($to->addDay())) {
                $item->update(['status' => RentableItem::STATUS_AVAILABLE]);
            }

            // The new amount takes effect the day the item stops being held.
            $this->rebuildCharge($lease->fresh(), ChargeScheduleService::billingBoundary($to->addDay()));
        });
    }

    /**
     * Re-derive the lease's single `parking` charge from the items it holds on a date.
     *
     * Summed rather than incremented: an increment is a delta against a number nobody verified, and
     * it drifts the first time an assignment is corrected. Recomputing from the register means the
     * charge is always exactly what the held items say it should be.
     */
    private function rebuildCharge(Lease $lease, CarbonImmutable $on): void
    {
        $total = (float) $lease->rentableItems()
            ->where(fn ($q) => $q->whereNull('lease_rentable_item.effective_from')
                ->orWhereDate('lease_rentable_item.effective_from', '<=', $on->toDateString()))
            ->where(fn ($q) => $q->whereNull('lease_rentable_item.effective_to')
                ->orWhereDate('lease_rentable_item.effective_to', '>=', $on->toDateString()))
            ->sum('lease_rentable_item.monthly_rate');

        $total = round($total, 2);

        // Nothing held any more → CLOSE the row, never open one at zero. `setAmount(0)` opened a
        // zero-amount row, and the billing run happily put "Parking & rentable items — EGP 0.00" on
        // every invoice for the rest of the term. A charge for nothing is not a charge.
        if ($total <= 0) {
            $current = $this->schedule->rowInForce($lease, 'parking', $on);

            if ($current) {
                $current->update([
                    'end_date' => $on->subDay()->toDateString(),
                    'is_active' => false,
                ]);
            }

            return;
        }

        // Rent is exempt, service charge is standard-rated, and parking is neither obviously — a
        // licence to use a space rather than a lease of it. The VAT Law schedules settle that and a
        // developer does not, so the answer is the accountant's: `charge_codes.tax_code` on the
        // `parking` code, shipping exempt because under-charging the tenant beats collecting tax
        // that may not be due and having to refund it. (It was a settings toggle of its own until
        // 2026-08-11, then `vat_treatment` until the tax catalogue replaced both on 2026-08-12 —
        // one question with three homes over three days, which is how they come to disagree.)
        //
        // Read at ORIGINATION only, so a ruling changes what is billed from then on and never
        // rewrites an issued invoice — the same rule the standard rate itself follows.
        $vatRate = Vat::rateForType('parking');

        $this->schedule->setAmount($lease, 'parking', $total, $on, [
            'name' => 'Parking & rentable items',
            'frequency' => 'monthly',
            'vat_applicable' => $vatRate > 0,
            'vat_rate' => $vatRate,
            // A bay taken on 1 March was not held in January. Without this the schedule's default
            // would date the first row to the lease commencement and back-charge the difference.
            'first_row_from_effective' => true,
        ], Charge::ORIGIN_MANUAL);
    }
}
