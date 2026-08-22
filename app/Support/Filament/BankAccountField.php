<?php

namespace App\Support\Filament;

use App\Models\BankAccount;
use App\Support\MoneyAccount;
use App\Support\TenantScope;

/**
 * "Which bank account did this money move through?" — one field, six money forms.
 *
 * The register has existed since 2026-08-11 and nothing on a money document pointed at it, so every
 * posting resolved the generic `bank` role. A mall banking in two places therefore put both banks'
 * money in one chart account, and the reconciliation matcher — which finds candidates BY that
 * account — offered the other bank's postings. See {@see MoneyAccount}.
 *
 * **Optional, always.** Null is the normal state and means "the rail decides", which is exactly what
 * happened before. Making it required would refuse every cash receipt and every install that has not
 * set the register up.
 *
 * An `EntitySelect`, not a `Select`: it picks a RECORD, so it gets the folded blob search, the
 * two-line option and — the part that matters — the property scope as a WRITE GUARD, because
 * Filament validates a Select by asking it to resolve the submitted value's label. Narrowing to the
 * SELECTED mall on top of that is this field's own choice: a receipt banked in Mall A's account is
 * not something you record while working in Mall B.
 */
final class BankAccountField
{
    public static function make(string $name = 'bank_account_id'): EntitySelect
    {
        return EntitySelect::make($name)
            ->label(__('admin.resources.bank_account.singular'))
            ->entity(BankAccount::class)
            // The PROPERTY clause is a hard filter, and is meant to be: `EntitySelect` resolves a
            // submitted value's LABEL through this query, so a value it cannot label is refused at
            // validation. That is the write guard.
            ->modifyOptionsQuery(fn ($query) => $query
                // `withTrashed()` for the same reason `is_active` is a suggestion and not a filter,
                // and it was the half the first fix missed: `OptionDisplay::pickable()` builds from
                // `$model::query()`, so the SoftDeletes global scope applies — and because the LABEL
                // lookup runs through this same query, a soft-deleted bank account made every
                // document naming it fail validation. Identical lockout, one column over. It also
                // keeps the picker agreeing with `MoneyAccount`, which reads `withTrashed()` on
                // purpose: money that moved through an account moved through it.
                ->withTrashed()
                ->when(
                    TenantScope::currentAssetId(),
                    fn ($q, $id) => $q->where('asset_id', $id),
                ))
            // `is_active` AND `deleted_at` narrow what you SEE, never what you can FIND — CLAUDE.md's
            // `->suggest()` rule, which the first cut broke by putting `is_active` in the hard
            // filter. Because the label lookup runs through that same query, retiring a bank
            // account made EVERY document naming it fail validation: an expense could no longer be
            // cancelled, re-dated or re-homed, on a field the operator cannot even edit. A closed
            // account should drop off the list for new documents and stay readable on the old ones.
            // Both conditions belong here together — deleting is just a louder way of retiring.
            ->suggest(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true))
            ->helperText(__('admin.helpers.bank_account_on_document'));
    }
}
