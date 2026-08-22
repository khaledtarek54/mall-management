<?php

namespace App\Models\Concerns;

use App\Models\BankAccount;
use App\Support\MoneyAccount;
use App\Support\TenantScope;
use DomainException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A money document that records WHICH bank account it moved through — and cannot name another
 * mall's.
 *
 * A `BankAccount` is `#[PropertyOwned]`, so pointing a Mall A payment at Mall B's account would post
 * Mall A's money into Mall B's bank chart account: a cross-property accounting error that balances,
 * and that the bank reconciliation would then present as a real candidate on the wrong statement.
 *
 * **The picker is not the guard.** `BankAccountField` narrows its options to the selected property,
 * and `EntitySelect`'s label lookup refuses a value it cannot resolve — but that lookup resolves
 * through the VISIBLE properties, not the selected one, so an operator holding two malls could
 * submit the other mall's account and it would validate. The value also arrives as a Livewire
 * payload, which is the reason CLAUDE.md gives for never treating a narrowed option list as a gate.
 *
 * Refused rather than silently nulled: recording a bank account that does not drive the posting
 * would be two truths about one document.
 */
trait RecordsBankAccount
{
    public static function bootRecordsBankAccount(): void
    {
        static::saving(function ($document): void {
            if (! $document->isDirty('bank_account_id') || $document->bank_account_id === null) {
                return;
            }

            // FOUR of the six carry their own `asset_id`; `payments` and `vendor_bill_payments` do
            // not — a receipt's books dimension is derived from the invoices it settles, and a
            // supplier payment's from its bill. For those, the mall the operator is working in is
            // the honest comparison: they are picking from a list scoped to it, and attaching
            // another mall's account is the mistake this refuses.
            //
            // Null on both — a console or API path with no property context — skips. A guard that
            // cannot know the answer must not invent one.
            $documentAsset = $document->asset_id
                ?? $document->bill?->asset_id
                ?? TenantScope::currentAssetId();

            if ($documentAsset === null) {
                return;
            }

            $bankAsset = BankAccount::withTrashed()
                ->whereKey($document->bank_account_id)
                ->value('asset_id');

            if ($bankAsset !== null && (int) $bankAsset !== (int) $documentAsset) {
                throw new DomainException(__('admin.errors.bank_account_other_property'));
            }
        });
    }

    /**
     * Which bank account this money actually moved through — nullable, and null is the normal state.
     *
     * When set, {@see MoneyAccount} posts to THAT account's chart account rather than
     * the generic `bank` role, which is what lets a mall banking in two places reconcile either one.
     * Until an operator says, the rail answers exactly as before and no balance moves.
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
