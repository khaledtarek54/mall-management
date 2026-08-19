<?php

namespace App\Support\Filament;

use App\Models\Asset;
use App\Support\TenantScope;
use Closure;

/**
 * The property picker, pinned to the mall the operator is standing in.
 *
 * ## What this fixes — a control that lies, not a leak
 *
 * The write path was already airtight before this existed, and that is the point. With a real
 * property selected, `TenantScope::visibleAssetIds()` returns `[currentId]`, so:
 *
 *  - a blank ("Consolidated") property reaches `GuardsAssetInScope::assertAssetInScope()` as
 *    `(int) null === 0`, which is not in `[currentId]` → **abort(403)**, for every role including
 *    super_admin; and
 *  - another mall's id fails validation before that, because {@see EntitySelect} resolves a
 *    submitted value's LABEL through the property-scoped `pickable()` query and Filament refuses
 *    what it cannot label → *"The selected property is invalid."*
 *
 * So every one of these pickers offered exactly ONE workable option and a set of dead ones. An
 * operator who chose "Consolidated (all)", filled in a whole journal entry and pressed Create met
 * a 403 wall with no explanation; one who picked a neighbouring mall met a validation error on a
 * field the form had actively invited them to change. The isolation held and the UI denied it.
 *
 * A disabled field showing the answer is therefore not a new guard — it is the screen finally
 * agreeing with the guard that was always there. Keep `assertAssetInScope()` wired anyway: this
 * field is `->dehydrated()`, so its value still arrives in the Livewire payload and a crafted
 * request can still state anything. **A disabled input is a statement of intent, never a gate.**
 *
 * ## Why `default()` and not a hard-coded value
 *
 * On EDIT, `default()` does not fire — the record's own `asset_id` loads and the field renders it
 * disabled, so re-saving an existing row cannot silently re-home it to whichever mall happens to
 * be selected. That matters most for the rows this system deliberately allows to carry no property
 * at all (see {@see PORTFOLIO_LEVEL} and `#[PropertyOwned(portfolioRowsWhenNull: true)]`), which
 * are visible from *every* property's list and would otherwise be captured by the first mall that
 * opened them.
 */
class PropertyField
{
    /**
     * The screens whose property picker stays FREE and nullable, and why.
     *
     * Every entry is portfolio CONFIGURATION or a portfolio-level request — a place where "no
     * property" is the normal, meaningful answer rather than an accident of leaving a dropdown
     * alone. `PropertyFieldPinnedConformanceTest` renders every other create form and fails the
     * build on one whose property field is editable, so this list is the only way to opt out and
     * a new one has to be argued for here.
     *
     * @var array<string, string>
     */
    public const PORTFOLIO_LEVEL = [
        'app/Filament/Admin/Resources/AccountMappings/Schemas/AccountMappingForm.php' => 'The posting map answers "which account does this role post to", and its GLOBAL row is the default every property inherits — a per-property row is the exception. Pinning it would make the global default unreachable and force the accountant to re-state the whole chart once per mall.',
        'app/Filament/Admin/Resources/Departments/Schemas/DepartmentForm.php' => 'Department is the one HYBRID model: a null asset_id is an operator-wide department (Finance, HR) that every mall shares, and a set one scopes it to a single mall. Both are ordinary. DepartmentResource reads "global OR your visible set" precisely because of this.',
        'app/Filament/Admin/Resources/OwnerRequests/Schemas/OwnerRequestForm.php' => 'An owner request is a conversation, not a document that posts. A general question ("when is the portfolio valuation due?") is about no single mall, and CreateOwnerRequest guards the property only when one was actually chosen.',
    ];

    /**
     * The pinned picker. Use this for anything that is a RECORD of a mall's business.
     *
     * `$alsoDisabledWhen` composes rather than replaces — a caller chaining its own `->disabled()`
     * after this would silently unpin the field, because Filament's `disabled()` overwrites. Pass
     * the extra lock (a posted document, a billed fine) here instead and both hold. It is evaluated
     * through the component, so it may take `?Model $record` like any other Filament callback.
     */
    public static function make(string $name = 'asset_id', Closure|bool|null $alsoDisabledWhen = null): EntitySelect
    {
        return EntitySelect::make($name)
            ->label(__('admin.fields.property'))
            ->entity(Asset::class)
            ->default(fn (): ?int => TenantScope::currentAssetId())
            ->disabled(function (EntitySelect $component) use ($alsoDisabledWhen): bool {
                if (TenantScope::currentAssetId() !== null) {
                    return true;
                }

                return $alsoDisabledWhen !== null && (bool) $component->evaluate($alsoDisabledWhen);
            })
            // Disabled fields are not submitted; without this the pinned property never reaches
            // the model and the row is created with none at all.
            ->dehydrated()
            ->required()
            ->native(false)
            ->helperText(fn (): ?string => TenantScope::currentAssetId() !== null
                ? __('admin.helpers.property_pinned')
                : null);
    }

    /**
     * The FREE picker, for the portfolio-configuration screens registered in {@see PORTFOLIO_LEVEL}.
     *
     * Identical scoping (it is still an `EntitySelect`, so it still cannot offer or accept a mall
     * the operator may not see) — it differs only in staying editable and allowing the blank that
     * means "every property".
     */
    public static function free(string $name = 'asset_id', ?string $blankMeans = null): EntitySelect
    {
        return EntitySelect::make($name)
            ->label(__('admin.fields.property'))
            ->entity(Asset::class)
            ->placeholder($blankMeans ?? __('admin.fields.property_all'))
            ->native(false)
            ->searchable();
    }

    /**
     * The report equivalent — same pin, bound to a page's `$assetId` rather than a column.
     *
     * The reports needed this more than the forms did. `TenantScope::reportAssetIds()` clamps the
     * chosen id to the visible set, and with a mall selected that set is `[currentId]` — so
     * "Consolidated (all)" and "the mall next door" both silently resolved to the mall you were
     * already in. The figures were right every time and the caption above them was wrong, which on
     * a trial balance is the more dangerous of the two failures: nobody re-checks a number they
     * believe they asked for.
     */
    public static function reportScope(string $name = 'assetId', ?Closure $afterStateUpdated = null): EntitySelect
    {
        $field = EntitySelect::make($name)
            ->label(__('admin.reports.property_scope'))
            ->entity(Asset::class)
            ->default(fn (): ?int => TenantScope::currentAssetId())
            ->disabled(fn (): bool => TenantScope::currentAssetId() !== null)
            ->dehydrated()
            ->native(false)
            ->live()
            ->helperText(fn (): ?string => TenantScope::currentAssetId() !== null
                ? __('admin.helpers.property_pinned')
                : null);

        return $afterStateUpdated !== null ? $field->afterStateUpdated($afterStateUpdated) : $field;
    }

    /** True when the panel is standing in one real mall — i.e. whenever the pin applies. */
    public static function isPinned(): bool
    {
        return TenantScope::currentAssetId() !== null;
    }
}
