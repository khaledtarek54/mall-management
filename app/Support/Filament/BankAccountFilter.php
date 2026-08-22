<?php

namespace App\Support\Filament;

use App\Models\BankAccount;
use App\Support\TenantScope;

/**
 * "Show me only what went through CIB." — the filter half of {@see BankAccountField}.
 *
 * An `EntitySelectFilter` for the reason every record picker here is an `EntitySelect`: it reads the
 * folded blob, so typing a bank's Arabic name or an account number finds it, and the chip renders as
 * text rather than option markup.
 *
 * Scoped to the selected property, like the field — but this is a READ, so the scope is a
 * convenience and not a guard: the table itself is already property-isolated, and a filter narrowing
 * an already-narrowed list cannot leak. RETIRED accounts stay in the list, unlike on the form: a
 * filter asks what money already WENT through, and dropping a closed account would hide every
 * document it ever carried.
 */
final class BankAccountFilter
{
    public static function make(string $name = 'bank_account_id'): EntitySelectFilter
    {
        return EntitySelectFilter::make($name)
            ->label(__('admin.resources.bank_account.singular'))
            ->entity(BankAccount::class)
            // Browsing rather than typing comes from `OptionDisplay::PRELOAD`, not from a
            // `->preload()` here — the registry is what keeps this filter and `BankAccountField`
            // answering the same way. Left to the call site they diverged: the field browses (it
            // passes `->suggest()`) while the filter fell to a static empty list and rendered
            // "start typing to search" on a mall holding exactly two accounts, which reads as
            // "there are no bank accounts".
            ->modifyOptionsQuery(fn ($query) => $query->when(
                TenantScope::currentAssetId(),
                fn ($q, $id) => $q->where('asset_id', $id),
            ));
    }
}
