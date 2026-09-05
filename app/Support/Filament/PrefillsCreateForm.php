<?php

namespace App\Support\Filament;

/**
 * Prefill a Create form from the query string WITHOUT throwing away its defaults.
 *
 * **`$form->fill($data)` REPLACES the state; it does not merge into the defaults.** So a page that
 * overrides `fillForm()` to carry a tenant or a lease across loses every default the schema
 * declares — measured on `CreateViolation`: opened plainly the form has `asset_id = 2` and
 * `status = 'open'`, and opened with `?for_tenant=1` both are MISSING. That is why the property
 * field arrived EMPTY on a panel where every other form shows it pinned and disabled: nothing was
 * wrong with `PropertyField`, the prefill had erased its value.
 *
 * The failure is quiet in the worst way. A blank required field reads as a form that has not
 * loaded yet, not as a link that broke it, and the operator's own instinct is to fill it in — so
 * on a form whose property is supposed to be PINNED, the prefill silently converts a guarded field
 * into a free one. The same call also drops `status`, a date default, and anything else declared.
 *
 * All three prefilling pages had it (`CreatePayment` and `CreateTenantSalesDeclaration` predate
 * this; `CreateViolation` shipped it on 2026-09-05), which is what makes it a seam rather than
 * three fixes: defaults first, prefill overlaid, one call.
 */
trait PrefillsCreateForm
{
    /**
     * @param  array<string, mixed>  $state  the values the link carried
     */
    protected function fillFormWithDefaults(array $state): void
    {
        // Defaults first — this is what a plain Create page does and all we are doing is not
        // discarding it.
        $this->form->fill();

        if ($state === []) {
            return;
        }

        // …then the prefill onto the named paths ONLY.
        //
        // **`fillPartially()`, never a second full `fill()`.** Re-filling the whole schema with
        // `[...getRawState(), ...$state]` restores the defaults and looks right, and it hydrates
        // every component a second time — which is wrong three ways. A REPEATER keeps the
        // `hydratedDefaultState` from the first pass, so any repeater declaring `->default([...])`
        // or `->relationship()` rebuilds itself from those defaults and DISCARDS the prefilled
        // rows: the seam written to stop state being lost silently would lose the prefill, which
        // is the whole point of the link. It also re-keys repeater rows to integers instead of
        // UUIDs, and it costs a full second hydration — measured on CreatePayment, 11 → 20 queries
        // and every EntitySelect label resolved twice.
        //
        // Filament has the targeted API for exactly this, so use it: only the paths the link
        // named are touched, and everything else keeps the default it was just given.
        //
        // **THE RAW WRITE IS OURS, BECAUSE `fillPartially()` CANNOT CARRY A NESTED VALUE.** It
        // writes state as `collect($state)->dot()->only($statePaths)`, so anything under a repeater
        // arrives dotted (`allocations.0.invoice_id`) while the path we can name is the repeater's
        // own (`allocations`) — `only()` matches neither against the other and the raw write is
        // EMPTY. Measured on `CreatePayment`: `?invoice=` filled the tenant and the amount (both
        // scalars, which `dot()` leaves alone) and the allocation came back
        // `invoice_id => null, allocated_amount => null` — one blank row, manufactured by
        // `minItems(1)`. That is the exact half `?invoice=` exists for: `suggestAllocations()`
        // spreads a receipt oldest-first, so a receipt raised to settle THIS invoice quietly lands
        // on another, and the form reads as merely un-prefilled rather than as wrong.
        //
        // WHOLE VALUES, not dotted leaves, and that is the second half of it: `partialRawState()`
        // `data_set`s each top-level key entire, so a repeater's rows REPLACE the blank default
        // row. Writing the leaves instead appends `allocations.0.*` BESIDE the default's uuid key
        // and the form opens with two rows, one of them empty and required.
        $this->form->partialRawState($state);

        // …and `fillPartially` still owns the hydration and the null-fill. Its own raw write is
        // now a no-op for the arrays and a rewrite of the same value for the scalars; what it is
        // here for is `hydrateStatePartially()`, which matches each component's state path against
        // these names — so the repeater hydrates from the rows just written.
        $this->form->fillPartially($state, array_keys($state));
    }
}
