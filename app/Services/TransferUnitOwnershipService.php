<?php

namespace App\Services;

use App\Enums\UnitOwnershipStatus;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\UnitOwnership;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * A unit changes hands — Yardi's change-of-ownership, with its resale certificate.
 *
 * In Voyager Condo/Co-Op & HOA a transfer closes the seller's ledger at a stated balance, opens the
 * buyer's, keeps the unit's history intact, and issues the **resale (estoppel) certificate** that
 * says what is owed at the moment of transfer. Both parties and their lawyers rely on that figure:
 * it is what the buyer's solicitor holds back from the price.
 *
 * Three rules this enforces, all of them Voyager's:
 *
 *  1. **The seller's tenure is CLOSED, never deleted.** His assessments, his CAM shares and his
 *     statements all point at that row; removing it would strand every one of them. Same rule the
 *     property-owner pivot follows.
 *  2. **The certificate is produced from the ledger, not typed.** An estoppel figure somebody keyed
 *     in is a figure nobody can stand behind.
 *  3. **Outstanding arrears do not silently pass to the buyer.** They are stated. Transferring over
 *     a debt is a decision the operator makes explicitly and it is recorded on the ownership.
 */
class TransferUnitOwnershipService
{
    /**
     * Close the seller's tenure, open the buyer's, and return the resale certificate.
     *
     * @param  bool  $allowOutstanding  transfer over an unpaid balance — a deliberate act, recorded
     * @return array{certificate: array<string, mixed>, seller: UnitOwnership, buyer: UnitOwnership}
     */
    public function transfer(
        UnitOwnership $ownership,
        Tenant $buyer,
        CarbonImmutable $on,
        bool $allowOutstanding = false,
        ?string $reason = null,
    ): array {
        return DB::transaction(function () use ($ownership, $buyer, $on, $allowOutstanding, $reason) {
            // Lock and re-read: two operators transferring the same unit must not each open a buyer
            // tenure, which would leave the unit owned twice on the same day.
            $seller = UnitOwnership::query()->lockForUpdate()->findOrFail($ownership->getKey());

            if ($seller->status?->isTerminal()) {
                throw new DomainException(__('admin.errors.unit_ownership_already_transferred'));
            }

            if (! $buyer->isUnitOwner()) {
                // A retailer is not a buyer. Recording a sale to one would put a party into the
                // ownership register that every owner-facing screen filters out again.
                throw new DomainException(__('admin.errors.unit_ownership_buyer_not_owner'));
            }

            if ($seller->started_at !== null && $on->lt($seller->started_at)) {
                throw new DomainException(__('admin.errors.unit_ownership_tenure_inverted'));
            }

            $certificate = $this->certificate($seller, $on);

            if (! $allowOutstanding && $certificate['outstanding'] > 0.005) {
                // Refused rather than warned. The buyer's solicitor holds back against this number;
                // letting it through silently is how a debt becomes the wrong person's.
                throw new DomainException(__('admin.errors.unit_ownership_transfer_blocked_arrears', [
                    'amount' => number_format($certificate['outstanding'], 2),
                ]));
            }

            $seller->update([
                'ended_at' => $on->subDay()->toDateString(),
                'status' => UnitOwnershipStatus::Transferred->value,
                'notes' => trim(($seller->notes ? $seller->notes."\n" : '')
                    ."Transferred {$on->toDateString()} to {$buyer->name}."
                    .($reason ? " {$reason}" : '')),
            ]);

            // The buyer inherits the TERMS, not the debt: same tenure type, same assessment basis,
            // same share of the unit. What he does not inherit is the seller's arrears, which the
            // certificate above states and which stay on the seller's own ledger.
            $bought = UnitOwnership::create([
                'asset_id' => $seller->asset_id,
                'unit_id' => $seller->unit_id,
                'tenant_id' => $buyer->id,
                'tenure_type' => $seller->tenure_type?->value,
                'assessment_basis' => $seller->assessment_basis?->value,
                'management_mode' => $seller->management_mode?->value,
                'ownership_share_pct' => $seller->ownership_share_pct,
                'participation_pct' => $seller->participation_pct,
                'status' => UnitOwnershipStatus::HandedOver->value,
                'started_at' => $on->toDateString(),
                'handover_date' => $on->toDateString(),
                'purchase_date' => $on->toDateString(),
                'payment_terms_days' => $seller->payment_terms_days,
                'currency' => $seller->currency,
            ]);

            return ['certificate' => $certificate, 'seller' => $seller->fresh(), 'buyer' => $bought];
        });
    }

    /**
     * The resale certificate — the account, as at the transfer date.
     *
     * Every figure is read from the books. `outstanding` is the number the sale turns on, so it is
     * the invoices' own `balance` (which `Invoice::recomputeTotals()` owns across all four
     * settlement channels) rather than anything re-derived here.
     *
     * @return array<string, mixed>
     */
    public function certificate(UnitOwnership $ownership, CarbonImmutable $asOf): array
    {
        $assessments = Invoice::query()
            ->where('unit_ownership_id', $ownership->id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereDate('issue_date', '<=', $asOf->toDateString())
            ->get();

        $monthly = $ownership->charges()
            ->where('is_active', true)
            ->where('frequency', 'monthly')
            ->sum('amount');

        return [
            'reference' => $ownership->reference,
            'as_of' => $asOf->toDateString(),
            'unit' => $ownership->unit?->code,
            'owner' => $ownership->owner?->name,
            'owned_from' => $ownership->started_at?->toDateString(),
            'assessments_billed' => round((float) $assessments->sum('total'), 2),
            'assessments_paid' => round((float) $assessments->sum('paid_amount'), 2),
            // THE number: what the buyer's side holds back against.
            'outstanding' => round((float) $assessments->sum('balance'), 2),
            'monthly_assessment' => round((float) $monthly, 2),
            'open_invoices' => $assessments->where('balance', '>', 0)
                ->map(fn (Invoice $i) => [
                    'number' => $i->number,
                    'due_date' => $i->due_date?->toDateString(),
                    'balance' => round((float) $i->balance, 2),
                ])->values()->all(),
        ];
    }
}
