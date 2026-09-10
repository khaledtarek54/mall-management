<?php

namespace App\Support\Filament;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;

/**
 * A field that collects MANY values refuses a payload that is not an array — one seam, every panel.
 *
 * **The hole (found by review, 2026-09-10).** Filament derives a Select's `Rule::in` from the options
 * it resolved — "the LABEL lookup IS the write guard", as this codebase says of every record
 * picker — and for a `->multiple()` Select it attaches that rule to `{path}.*`, the CHILDREN of the
 * submitted array (`Select::hasInValidationOnMultipleValues()`), with no `array` rule on the path
 * itself. A SCALAR payload — `'supervisors' => 5` instead of `[5]` — therefore has no children to
 * validate, passes, is wrapped into an array by the state cast, and syncs. Measured on the
 * property page's Zones tab and on `CreateArea`: a staff member from another mall, refused as
 * `supervisors.0` when sent as an array, went straight through when sent bare, and only a
 * post-save guard stood between it and the pivot. `CheckboxList` derives `In` the same way.
 *
 * Thirty-one `->multiple()` fields across three panels carry that shape, and a post-save guard is
 * the exception rather than the rule — the CAM pool's account picker, a lease's percentage-rent
 * exclusion sets and the vendor's trades have none. Every one is a value outside the options the
 * operator was offered. No unguarded PROPERTY-OWNED picker was found (the two unit pickers are
 * re-checked by their services, the user form's property grant by `enforceGrantableAssetsRule()`),
 * so this is an options bypass rather than a measured cross-mall leak; the seam is what keeps
 * that sentence true rather than lucky.
 *
 * `configureUsing` on `Select::class` reaches `EntitySelect` and `CatalogueAwareSelect` too —
 * `ComponentManager::configure()` walks `class_parents()` — which is the whole reason this is one
 * registration and not a trait on three classes. The rule is evaluated lazily so a `multiple()`
 * decided by closure is honoured, and Filament already adds `nullable` to every non-required
 * field, so an empty optional multi-select still passes.
 */
final class MultiValueFieldIsAnArray
{
    public static function register(): void
    {
        Select::configureUsing(fn (Select $select) => $select
            ->rule('array', fn (Select $component): bool => $component->isMultiple()));

        CheckboxList::configureUsing(fn (CheckboxList $list) => $list->rule('array'));
    }
}
