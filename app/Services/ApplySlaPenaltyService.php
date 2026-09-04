<?php

namespace App\Services;

use App\Models\SlaPenalty;
use App\Models\VendorBill;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Charges an assessed SLA penalty to the vendor's bill (FR-CM-08, money half).
 *
 * `vendor_bills` has no line items and `balance` is DERIVED by `VendorBill::recompute()` —
 * the single source of truth for AP settlement, mirroring the Invoice AR invariant. So a
 * penalty is never "a line on the bill": it is a second offset that recompute() folds in,
 * exactly as `credit_applied_amount` works on the tenant side. Nothing here writes
 * `balance`; it calls recompute() and lets the model own it.
 */
class ApplySlaPenaltyService
{
    /**
     * Deduct a chargeable penalty from a bill.
     *
     * @throws DomainException when the penalty or bill isn't eligible
     */
    public function toBill(SlaPenalty $penalty, VendorBill $bill): SlaPenalty
    {
        return DB::transaction(function () use ($penalty, $bill) {
            /** @var SlaPenalty $lockedPenalty */
            $lockedPenalty = SlaPenalty::whereKey($penalty->getKey())->lockForUpdate()->firstOrFail();
            /** @var VendorBill $lockedBill */
            $lockedBill = VendorBill::whereKey($bill->getKey())->lockForUpdate()->firstOrFail();

            $this->assertChargeable($lockedPenalty);
            $this->assertBillEligible($lockedPenalty, $lockedBill);

            $lockedPenalty->update([
                'status' => SlaPenalty::STATUS_APPLIED,
                'vendor_bill_id' => $lockedBill->getKey(),
                'applied_at' => now(),
            ]);

            // recompute() re-reads the applied penalties and re-derives the balance; it is
            // the only thing that may write it.
            $lockedBill->refresh()->recompute();

            return $lockedPenalty->refresh();
        });
    }

    /**
     * Un-charge a penalty — the deduction was disputed, or applied to the wrong bill. The
     * penalty returns to `final` (still owed, not yet deducted) rather than disappearing.
     *
     * @throws DomainException if it isn't currently applied
     */
    public function detach(SlaPenalty $penalty): SlaPenalty
    {
        return DB::transaction(function () use ($penalty) {
            /** @var SlaPenalty $locked */
            $locked = SlaPenalty::whereKey($penalty->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isApplied()) {
                throw new DomainException(__('admin.facility.errors.penalty_not_applied'));
            }

            // THE BILL, LOCKED, BEFORE ANYTHING PLAIN-READS IT. `recompute()` below rewrites the
            // whole bill's `penalty_applied_amount`/`balance`/`status` from every applied penalty on
            // it, so a release is a whole-bill write and must serialise on the bill row exactly as
            // `toBill()` above already does. Until now this was `$locked->bill` — both unserialised
            // AND the plain read that fixes the transaction's snapshot, so a detach racing an apply
            // wrote a balance with the other penalty's deduction erased and overstated the payable by
            // that penalty. See `SlaPenalty::billForUpdate()`.
            //
            // Captured before the update, because clearing `vendor_bill_id` loses the link.
            $bill = $locked->billForUpdate();

            $locked->update([
                'status' => SlaPenalty::STATUS_FINAL,
                'vendor_bill_id' => null,
                'applied_at' => null,
            ]);

            // No `refresh()`: `billForUpdate()` IS the fresh read, taken after the wait.
            $bill?->recompute();

            return $locked->refresh();
        });
    }

    /** @throws DomainException */
    private function assertChargeable(SlaPenalty $penalty): void
    {
        if ($penalty->isApplied()) {
            throw new DomainException(__('admin.facility.errors.penalty_already_applied'));
        }

        if ($penalty->isWaived()) {
            throw new DomainException(__('admin.facility.errors.penalty_is_waived'));
        }

        // `pending` means the job is still open and the amount can still move — charging it
        // would deduct a figure that is about to change.
        if (! $penalty->isChargeable()) {
            throw new DomainException(__('admin.facility.errors.penalty_still_accruing'));
        }
    }

    /** @throws DomainException */
    private function assertBillEligible(SlaPenalty $penalty, VendorBill $bill): void
    {
        // You cannot deduct one vendor's penalty from another vendor's bill.
        if ((int) $bill->vendor_id !== (int) $penalty->vendor_id) {
            throw new DomainException(__('admin.facility.errors.penalty_wrong_vendor'));
        }

        // ...nor from another PROPERTY's bill. A vendor commonly serves several malls, so
        // vendor_id alone is not the same question as asset_id — that is exactly why this
        // was missed. SlaPenaltyJournalizer dimensions the entry to `$bill->asset_id`
        // ("the two must land in the same books to net off"), which is only true when the two
        // properties agree: charging AAA's penalty to BBB's bill makes BBB absorb a recovery
        // it never earned, and AAA never sees it. The exact sibling of the cross-property
        // stock draw that WorkOrderPartService::assertWarehouseServesOrder() already blocks.
        if ((int) $bill->asset_id !== (int) $penalty->asset_id) {
            throw new DomainException(__('admin.facility.errors.penalty_wrong_property'));
        }

        // A draft bill isn't on the books yet and a cancelled one owes nothing — deducting
        // from either would post a GL entry against a payable that does not exist.
        if (! $bill->isPostable()) {
            throw new DomainException(__('admin.facility.errors.penalty_bill_not_postable'));
        }

        // Never let a deduction exceed what is still payable: AP would go negative, which
        // is a receivable wearing a payable's clothes. Split it across bills instead.
        $available = round((float) $bill->balance, 2);

        if (round((float) $penalty->amount, 2) > $available) {
            throw new DomainException(__('admin.facility.errors.penalty_exceeds_bill', [
                'amount' => number_format((float) $penalty->amount, 2),
                'balance' => number_format($available, 2),
            ]));
        }
    }
}
