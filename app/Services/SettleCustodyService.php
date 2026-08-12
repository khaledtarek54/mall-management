<?php

namespace App\Services;

use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\User;
use App\Support\PostingDate;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Records a settlement against a custody (module 25) — an `expense` (with a category)
 * or a `return` of unspent cash (cash|bank). Lock-safe: the custody is locked and its
 * outstanding re-computed inside the transaction, so concurrent settlements can never
 * over-settle (spend more than was placed in custody).
 */
class SettleCustodyService
{
    /**
     * @param  array{type?:string, amount:mixed, transaction_date:mixed, category?:?string, method?:string, notes?:?string}  $data
     */
    public function settle(Custody $custody, array $data): CustodyTransaction
    {
        return DB::transaction(function () use ($custody, $data) {
            /** @var Custody $locked */
            $locked = Custody::whereKey($custody->getKey())->lockForUpdate()->firstOrFail();

            $amount = round((float) ($data['amount'] ?? 0), 2);
            abort_unless($amount > 0, 422);
            // Re-check outstanding UNDER the lock — no over-settlement under a race.
            abort_unless($amount <= $locked->outstanding() + 0.001, 422);

            // The date decides which period the GL entry lands in, so it must be able to
            // carry one. Without this the row committed, outstanding dropped, and the
            // posting failed inside the best-effort queued job — leaving the operator told
            // it worked while the GL still showed the full custody outstanding and no
            // expense (gap-analysis F-93). Refuse here rather than in the form: the form is
            // UX, and the service is the only thing the API and console also pass through.
            $transactionDate = PostingDate::assertNotFuture(
                $data['transaction_date'] ?? null,
                __('admin.custodies.txn_fields.date'),
            );

            // A settlement cannot predate the cash it settles. Otherwise the Cr Custodies
            // lands in an earlier period than the Dr grant, and that month's trial balance
            // shows a CREDIT balance on an asset account.
            if ($transactionDate->startOfDay()->isBefore($locked->custody_date->startOfDay())) {
                throw new DomainException(__('admin.custodies.errors.settlement_before_grant', [
                    'granted' => $locked->custody_date->toDateString(),
                ]));
            }

            $type = ($data['type'] ?? 'expense') === 'return' ? 'return' : 'expense';

            return $locked->transactions()->create([
                'asset_id' => $locked->asset_id, // denormalised — the books dimension
                'type' => $type,
                'amount' => $amount,
                // The validated, normalised date — not the raw input.
                'transaction_date' => $transactionDate->toDateString(),
                // Category only for expenses; method (cash|bank) only for returns.
                'category' => $type === 'expense' ? ($data['category'] ?? 'other') : null,
                'method' => $type === 'return' ? (($data['method'] ?? 'cash') === 'bank' ? 'bank' : 'cash') : null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => auth()->id(),
            ]);
        });
    }

    /**
     * Reverse a settlement — the correction path custody was missing (gap-analysis F-94).
     *
     * Every other money document in Atriom can be corrected (invoice → credit note, journal
     * entry → void, vendor bill → void, payroll → cancel); a mis-keyed custody settlement could
     * not, so a single typo left `outstanding` wrong and the GL overstating/understating an
     * expense with no way back short of super_admin deleting the WHOLE custody — which cascades
     * and voids the correct grant entry too.
     *
     * A soft-delete IS the void here, and cleanly: `Custody::settled()` sums the soft-delete-aware
     * `transactions()` relation, so the amount drops straight back out of `outstanding`; and
     * `CustodyTransaction` is a registered GL source whose real-time sync fires on `deleted`,
     * so `LedgerPoster::sync()` sees a trashed source and voids its journal entry. The row is
     * retained (withTrashed) for the audit trail, and this stamps an explicit `reversed` activity
     * with the causer + reason so the books answer who/when/why — the row alone would only say
     * "deleted". If this was the custody's only settlement, `transactions()->exists()` is now
     * false, so the amount unlocks on the form, exactly as it was before the first settlement.
     *
     * Lock-safe: the custody is locked so a reverse can't race a concurrent settlement, and the
     * transaction is re-read under the lock so it can't be reversed twice.
     *
     * @throws DomainException if it is already reversed
     */
    public function reverse(CustodyTransaction $transaction, string $reason, ?int $actorId = null): CustodyTransaction
    {
        return DB::transaction(function () use ($transaction, $reason, $actorId) {
            // Lock the parent to serialise against a concurrent settle/reverse on the same custody.
            Custody::whereKey($transaction->custody_id)->lockForUpdate()->firstOrFail();

            /** @var CustodyTransaction|null $locked */
            $locked = CustodyTransaction::whereKey($transaction->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new DomainException(__('admin.custodies.errors.settlement_already_reversed'));
            }

            // Capture who/why BEFORE the soft-delete, so the reason survives on a retained row.
            activity('custody_transaction')
                ->performedOn($locked)
                ->causedBy($actorId ? User::find($actorId) : auth()->user())
                ->event('reversed')
                ->withProperties(['reason' => $reason, 'amount' => (float) $locked->amount])
                ->log('custody_transaction.reversed');

            // The soft-delete is the void: outstanding recomputes (settled() excludes trashed),
            // and the real-time GL sync voids the entry.
            $locked->delete();

            return $locked;
        });
    }
}
