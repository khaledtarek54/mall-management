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
        $this->form->fillPartially($state, array_keys($state));
    }
}
