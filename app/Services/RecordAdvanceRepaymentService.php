<?php

namespace App\Services;

use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Support\PostingDate;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Records a repayment against an employee advance/loan (module 24, Phase 2).
 * Lock-safe: the advance is locked and its outstanding re-computed inside the
 * transaction, so concurrent repayments can never over-repay (drive the receivable
 * negative). Denormalises the property from the advance for the GL dimension.
 */
class RecordAdvanceRepaymentService
{
    /**
     * @param  array{amount:mixed, repaid_on:mixed, method?:string, notes?:?string}  $data
     */
    public function record(EmployeeAdvance $advance, array $data): EmployeeAdvanceRepayment
    {
        return DB::transaction(function () use ($advance, $data) {
            /** @var EmployeeAdvance $locked */
            $locked = EmployeeAdvance::whereKey($advance->getKey())->lockForUpdate()->firstOrFail();

            $amount = round((float) ($data['amount'] ?? 0), 2);
            abort_unless($amount > 0, 422);
            // Re-check outstanding UNDER the lock — no over-repayment under a race.
            abort_unless($amount <= $locked->outstanding() + 0.001, 422);

            // Same guard, same reason as SettleCustodyService — see App\Support\PostingDate.
            // Without it the repayment committed, outstanding dropped, and the GL posting
            // died in the best-effort queue: Employee Advances stayed at the full amount and
            // the cash was never recorded, while the accountant saw "Recorded ✓"
            // (gap-analysis F-89).
            $repaidOn = PostingDate::assertNotFuture(
                $data['repaid_on'] ?? null,
                __('admin.employees.advance_fields.repaid_on'),
            );

            // A repayment cannot predate the advance it repays.
            if ($repaidOn->startOfDay()->isBefore($locked->advance_date->startOfDay())) {
                throw new DomainException(__('admin.employees.errors.repayment_before_advance', [
                    'granted' => $locked->advance_date->toDateString(),
                ]));
            }

            return $locked->repayments()->create([
                'asset_id' => $locked->asset_id, // denormalised — the books dimension
                'amount' => $amount,
                'repaid_on' => $repaidOn->toDateString(),
                'method' => ($data['method'] ?? 'cash') === 'bank' ? 'bank' : 'cash',
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => auth()->id(),
            ]);
        });
    }

    /**
     * Reverse a mis-keyed repayment (gap-analysis F-91). Every other money document in Atriom
     * can be corrected; a repayment could not — a 5,000 typed for 500 left outstanding wrong
     * and cash overstated, unfixable short of super_admin deleting the WHOLE advance (which
     * cascades and voids the correct grant entry too).
     *
     * The soft-delete IS the void: `EmployeeAdvance::repaid()` sums the soft-delete-aware
     * `repayments()` relation (so outstanding goes straight back up), and
     * `EmployeeAdvanceRepayment` is a registered GL source whose real-time sync fires on
     * `deleted` (so `LedgerPoster::sync()` sees a trashed source and voids its entry). The row
     * is retained for audit with an explicit `reversed` activity capturing who + why.
     *
     * Lock-safe: the advance is locked so a reverse can't race a concurrent repayment, and the
     * repayment is re-read under the lock so it can't be reversed twice.
     *
     * @throws DomainException if it is already reversed
     */
    public function reverse(EmployeeAdvanceRepayment $repayment, string $reason, ?int $actorId = null): EmployeeAdvanceRepayment
    {
        return DB::transaction(function () use ($repayment, $reason, $actorId) {
            // Lock the parent advance to serialise against a concurrent repayment/reverse.
            EmployeeAdvance::whereKey($repayment->employee_advance_id)->lockForUpdate()->firstOrFail();

            /** @var EmployeeAdvanceRepayment|null $locked */
            $locked = EmployeeAdvanceRepayment::whereKey($repayment->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new DomainException(__('admin.employees.errors.repayment_already_reversed'));
            }

            // Capture who/why BEFORE the soft-delete, so the reason survives on a retained row.
            activity('employee_advance_repayment')
                ->performedOn($locked)
                ->causedBy($actorId ? \App\Models\User::find($actorId) : auth()->user())
                ->event('reversed')
                ->withProperties(['reason' => $reason, 'amount' => (float) $locked->amount])
                ->log('reversed');

            $locked->delete();

            return $locked;
        });
    }
}
