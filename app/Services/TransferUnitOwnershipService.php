<?php

namespace App\Services;

use App\Enums\UnitOwnershipStatus;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\UnitOwnership;
use App\Services\BillUnitOwnershipsService;
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
 *
 * ## The month the sale falls in (added 2026-08-19, pre-staging QA F-02)
 *
 * An assessment is raised on the 1st for the whole month, and a sale completes on the 11th. Two
 * things then have to happen, and neither used to:
 *
 *  - **The seller is credited the days they did not own.** `Transferred` is not a billable status,
 *    so the run never revisits them — measured, a seller stayed billed 3,000.00 for a month they
 *    owned ten days of, permanently, with nothing anywhere correcting it. The credit uses
 *    `CreditUnearnedBillingService`, the same instrument and the same `monthsCovered()` proration
 *    the lease side has used since MF-02, so a mid-month resale and a mid-month move-out can never
 *    give back different amounts for the same shape of month.
 *  - **The buyer gets a schedule.** The tenure carried the terms and not the assessment rows, so a
 *    buyer was billable in principle and had nothing to bill — every month, forever. The seller's
 *    ACTIVE recurring rows are copied forward from the transfer date; one-offs are not, because a
 *    one-off was an event on the seller's holding, not a term of the unit.
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

            // Give back the part of the month the seller has been billed and will not own. Inside
            // this transaction: a transfer that then fails must not leave a credit note standing
            // against a sale that never happened.
            $sellerCredits = app(CreditUnearnedBillingService::class)
                ->forOwnershipTransfer($seller->fresh(), $on);

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

            // Carry the assessment schedule onto the buyer, dated from the day their tenure opens.
            //
            // RECURRING rows only. A one-off the seller was charged — a special levy, a fit-out
            // contribution — was an event on their holding; re-opening it on the buyer would bill
            // the same one-off twice for one unit.
            foreach ($seller->charges()->where('is_active', true)->where('frequency', '!=', 'one_time')->get() as $row) {
                /** @var Charge $row */
                Charge::create([
                    'unit_ownership_id' => $bought->getKey(),
                    'name' => $row->name,
                    'type' => $row->type,
                    'origin' => Charge::ORIGIN_RENEWAL,
                    'amount' => $row->amount,
                    'currency' => $row->currency ?? $bought->currency ?? 'EGP',
                    'frequency' => $row->frequency,
                    // NOT `(bool)`: that cast turned null into FALSE, so transferring a unit
                    // would have re-frozen "ask the catalogue" into "permanently exempt" on the new
                    // owner's rows — reintroducing EG-01's bug one resale at a time.
                    'vat_applicable' => $row->vat_applicable,
                    // The OVERRIDE is carried, not the resolved rate — null stays null so the
                    // catalogue keeps answering for each invoice's own date.
                    'vat_rate' => $row->vat_rate,
                    // Same reasoning as the renewal path: a resale copies the seller's schedule, so
                    // dropping the timing would re-bill the buyer a month the seller already paid.
                    'billing_timing' => $row->billing_timing,
                    'start_date' => $on->toDateString(),
                    'is_active' => true,
                ]);
            }

            // Close the seller's rows on their last owned day, so the register shows a holding that
            // ended rather than one still accruing. `is_active` false also keeps them out of the
            // overlap guard's way if the same unit is ever sold back.
            $seller->charges()->where('is_active', true)->update([
                'end_date' => $on->subDay()->toDateString(),
                'is_active' => false,
            ]);

            // Bill the buyer for the rest of the transfer month — the other half of F-02, and the
            // half that was still losing money.
            //
            // The scheduled run raises the assessment on the 1st and the sale completes on the
            // 11th. The seller is credited above for the days they will not own, and the buyer's
            // schedule opens on the 11th — but the monthly run bills the CURRENT period, so when it
            // next fires it raises November. Nobody ever goes back for 11–31 October: the seller
            // was refunded it and the buyer was never charged it, so the unit is short a third of a
            // month, silently and permanently. A manual re-run could recover it and nothing asks
            // anyone to do that.
            //
            // Only when the month was ALREADY billed. If it has not been billed yet the ordinary
            // run will pick the buyer up on its own, and billing here would raise the assessment
            // twice for one month.
            $buyerInvoice = null;
            $monthStart = $on->startOfMonth();
            $monthEnd = $on->endOfMonth();

            $sellerWasBilledThisMonth = Invoice::query()
                ->where('unit_ownership_id', $seller->getKey())
                ->whereDate('period_start', '<=', $monthEnd->toDateString())
                ->whereDate('period_end', '>=', $monthStart->toDateString())
                ->exists();

            if ($sellerWasBilledThisMonth) {
                // `billOne()` re-reads under its own lock, refuses a period already billed and
                // prorates on the buyer's tenure — so this cannot double-bill and cannot disagree
                // with what the scheduled run would have produced. Reused rather than reimplemented
                // for exactly that reason: a second proration here is a second answer to one
                // question.
                $buyerInvoice = app(BillUnitOwnershipsService::class)
                    ->billOne($bought->fresh(), $monthStart, $monthEnd);
            }

            $bought->setAttribute('transfer_credit_notes', collect($sellerCredits));
            $bought->setAttribute('transfer_buyer_invoice', $buyerInvoice);

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
