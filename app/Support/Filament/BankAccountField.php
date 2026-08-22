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
            ->modifyOptionsQuery(fn ($query) => $query
                // ACTIVE only: a closed account is history, and offering it invites a posting into
                // an account nobody is reconciling any more.
                ->where('is_active', true)
                ->when(
                    TenantScope::currentAssetId(),
                    fn ($q, $id) => $q->where('asset_id', $id),
                ))
            ->helperText(__('admin.helpers.bank_account_on_document'));
    }
}
