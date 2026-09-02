<?php

namespace App\Models\Concerns;

use App\Models\BankAccount;
use App\Models\PaymentMethod;
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
    /**
     * On `creating`/`updating`, deliberately NOT on `saving`.
     *
     * A trait's boot method runs during `bootTraits()`, which is BEFORE the class's own `booted()`
     * — so a listener registered here on `saving` fires before every `saving` hook the model itself
     * declares. Two of these documents DERIVE the property they belong to in exactly such a hook
     * (`DepositTransaction` from its lease's unit, and `VendorBillPayment` reads it from the bill),
     * so on `saving` this code sees `asset_id === null` on precisely the documents whose property is
     * not typed by hand.
     *
     * Measured: a security-deposit receipt created from a lease was never defaulted at all, and —
     * the half that was already shipped and wrong — the cross-property GUARD skipped it too, so a
     * deposit receipt naming ANOTHER MALL's bank account was accepted on create. `creating` and
     * `updating` both fire after every `saving` hook has run, which is the only point at which the
     * question "which property is this?" has an answer.
     */
    public static function bootRecordsBankAccount(): void
    {
        static::creating(function ($document): void {
            self::defaultBankAccountOnto($document);
            self::assertBankAccountInProperty($document);
        });

        static::updating(function ($document): void {
            self::assertBankAccountInProperty($document);
        });
    }

    /** A document may never name a bank account belonging to another mall. */
    private static function assertBankAccountInProperty($document): void
    {

        if ($document->bank_account_id === null) {
            return;
        }

        // BOTH ends of the move. Checking only `bank_account_id` let the other side through:
        // re-homing a document to another mall while leaving its bank account alone ends with a
        // Mall B expense pointing at Mall A's account, which is the same wrong posting arrived
        // at from the opposite direction. Same reasoning as `GuardsPortfolioWideRows`.
        if (! $document->isDirty('bank_account_id') && ! $document->isDirty('asset_id')) {
            return;
        }

        // The comparison the refusal rests on. Resolved by the same helper the default uses, so
        // the two can never disagree about which mall a document belongs to — one of them
        // choosing an account and the other checking it against a different property would be a
        // guard that passes exactly what it exists to refuse.
        $documentAsset = self::bankAccountAssetOf($document);

        if ($documentAsset === null) {
            return;
        }

        $bankAsset = BankAccount::withTrashed()
            ->whereKey($document->bank_account_id)
            ->value('asset_id');

        if ($bankAsset !== null && (int) $bankAsset !== (int) $documentAsset) {
            throw new DomainException(__('admin.errors.bank_account_other_property'));
        }

    }

    /**
     * Fill the account nobody typed — the half that makes requiring one reasonable.
     *
     * This is the mechanism Yardi relies on and the reason its cash account can be mandatory: in
     * Voyager the receipt DEFAULTS its bank from the property, so the operator confirms rather than
     * chooses. Requiring an answer without offering one is the worst half of that design — an
     * operator picking the same value three hundred times a month eventually picks the wrong one,
     * and a wrong bank account is worse than none, because
     * `MatchBankStatementLineService::candidatesFor()` finds candidates BY the chart account and
     * would present the mistake as a real match against the wrong statement.
     *
     * **On CREATE only.** An existing document keeps whatever it has, including null:
     * `bank_account_id` is classified DERIVED, so writing one onto a committed document would make
     * {@see App\Support\LedgerPoster::sync()} void its posted entry and re-post it to a different
     * cash account — a migration silently restating the books, which is the one thing this must
     * never do.
     *
     * **Only when the RAIL says an account is part of the record.** A cash receipt gets nothing, so
     * the till never acquires a bank account it did not move through.
     *
     * **Only when the property can be resolved AND has said where its money lands.** With no
     * default flagged and more than one account registered, {@see BankAccount::defaultFor()} returns
     * null and the document keeps naming nothing — the floor in {@see MoneyAccount} then answers
     * exactly as it does today. A guess is the one outcome not on offer.
     */
    private static function defaultBankAccountOnto($document): void
    {
        if ($document->exists || $document->bank_account_id !== null) {
            return;
        }

        if (! PaymentMethod::requiresBankAccount($document->{static::bankAccountRailColumn()})) {
            return;
        }

        $assetId = self::bankAccountAssetOf($document);

        if ($assetId === null) {
            return;
        }

        $document->bank_account_id = BankAccount::defaultFor($assetId, static::bankAccountPurpose())?->id;
    }

    /**
     * The property this document's money belongs to, for both the default and the guard.
     *
     * FOUR of the seven carry their own `asset_id`; `payments` and `vendor_bill_payments` do not — a
     * receipt's books dimension is derived from the invoices it settles, and a supplier payment's
     * from its bill. For those the mall the operator is working in is the honest answer: they are
     * picking from a list scoped to it.
     *
     * Null on all of them — a console, queue or API path with no property context — means no answer,
     * and both callers treat that as "do nothing". A guard that cannot know must not invent, and
     * neither must a default.
     */
    private static function bankAccountAssetOf($document): ?int
    {
        $assetId = $document->asset_id
            ?? $document->bill?->asset_id
            ?? TenantScope::currentAssetId();

        return $assetId === null ? null : (int) $assetId;
    }

    /**
     * Which kind of account this document's money belongs in — the ONE statement of it.
     *
     * Read by the model default above AND by `App\Support\Filament\BankAccountField`, so the form
     * and the fallback cannot disagree about where a deposit receipt banks. Overridden on the two
     * documents whose money is not the operator's working cash: a security deposit (a liability the
     * operator holds) and a payroll run (Egyptian banks want a salary account for the transfer file).
     */
    public static function bankAccountPurpose(): string
    {
        return BankAccount::PURPOSE_OPERATING;
    }

    /**
     * The column naming the rail this document's money moved on.
     *
     * `method` on the inbound documents and the two outbound ones that call it that; `paid_from` on
     * the expense-shaped ones. One accessor rather than seven call sites reading the right string,
     * because the requirement, the default and the field all have to ask the same column.
     */
    public static function bankAccountRailColumn(): string
    {
        return 'method';
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
        // `withTrashed()`, so the register and the ledger tell one story. `MoneyAccount` reads the
        // account with trashed rows included on purpose — money that moved through an account moved
        // through it, and a deleted register row must not rewrite posting history — and without the
        // same treatment here the LIST said "the rail decides" about a document the GL was still
        // posting to that very bank. One truth about which account this money moved through.
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }
}
