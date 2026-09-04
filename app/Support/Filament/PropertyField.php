<?php

namespace App\Support\Filament;

use App\Models\Asset;
use App\Support\TenantScope;
use Closure;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;

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
     * The screens that take {@see scope()} instead of {@see make()}, and why.
     *
     * Every entry is portfolio CONFIGURATION or a portfolio-level request — a place where "no
     * property" is the normal, meaningful answer rather than an accident of leaving a dropdown
     * alone. `PropertyFieldPinnedConformanceTest` renders every other create form and fails the
     * build on one whose property field is editable, so this list is the only way to opt out and
     * a new one has to be argued for here.
     *
     * **These are not unpinned.** Until 2026-08-24 they rendered a free `EntitySelect`, which was
     * never an isolation leak — the picker resolves a submitted value's label through the
     * property-scoped `pickable()` query, so on a two-mall install it offered exactly the mall in
     * the switcher and refused the other at validation. What was wrong is that a SCOPE question
     * wore a PROPERTY PICKER: it read as "choose a mall", so an enabled dropdown looked like a leak
     * on the one screen family where it was not one, and that is how it was reported. They now
     * state the two answers — the house row, or the mall you are standing in — and no screen in the
     * panel offers a property other than the selected one. `PropertyScopeControlNeverOffersAnotherMallTest`
     * derives this list and fails on a screen that starts offering a third.
     *
     * @var array<string, string>
     */
    public const PORTFOLIO_LEVEL = [
        'app/Filament/Admin/Resources/AccountMappings/Schemas/AccountMappingForm.php' => 'The posting map answers "which account does this role post to", and its GLOBAL row is the default every property inherits — a per-property row is the exception. Pinning it would make the global default unreachable and force the accountant to re-state the whole chart once per mall.',
        'app/Filament/Admin/Resources/Departments/Schemas/DepartmentForm.php' => 'Department is the one HYBRID model: a null asset_id is an operator-wide department (Finance, HR) that every mall shares, and a set one scopes it to a single mall. Both are ordinary. DepartmentResource reads "global OR your visible set" precisely because of this.',
        'app/Filament/Admin/Resources/DocumentTemplates/Schemas/DocumentTemplateForm.php' => 'The wording on a document is HOUSE text first: a null asset_id is the portfolio default every mall inherits, and a row naming a mall is the override — bank details, mostly, since two malls can bank in two places. Pinning it would make the default unreachable through its own form and force the operator to write the same footer once per property.',
        'app/Filament/Admin/Resources/Holidays/Schemas/HolidayForm.php' => 'A NATIONAL holiday is the ordinary case and it is a null asset_id — Eid is not a fact about one mall. Pinning would make the ordinary case unreachable through its own form, because a pinned blank reaches assertAssetInScope((int) null === 0) as a bare 403. A row naming a property is the exception, and it is what lets one mall trade through a national holiday.',
        'app/Filament/Admin/Resources/OwnerRequests/Schemas/OwnerRequestForm.php' => 'An owner request is a conversation, not a document that posts. A general question ("when is the portfolio valuation due?") is about no single mall, and CreateOwnerRequest guards the property only when one was actually chosen.',
    ];

    /**
     * Property controls that are deliberately NOT this component, and why.
     *
     * The companion to {@see PORTFOLIO_LEVEL}, and a separate list because the reasons are a
     * different kind: these are not screens choosing to stay free, they are controls that are not
     * asking an operator which mall to file something under at all. `PropertyControlsAreAccountedFor`
     * sweeps every `make('asset_id')` / `make('assetId')` under `app/Filament` and fails on one that
     * is neither built by this class nor listed here — which is what stops the next relation manager
     * or header-action form from quietly reinventing the free picker in a directory the rendered
     * create-form sweep never visits.
     *
     * @var array<string, string>
     */
    public const UNPINNED = [
        'app/Filament/Admin/Pages/Concerns/MapsOneProperty.php' => 'The picker BOTH floor plans take (OccupancyMap and RentableItemMap), which is why the entry names the concern rather than either page — it moved here on 2026-08-26 when the second map arrived. Its whole filter Section is `->visible(fn () => $this->isAllPropertiesMode())`, i.e. `currentAssetId() === null`, so it is never rendered while a mall is selected — the pin would be a no-op on a control that cannot appear. It exists for the All-Properties plumbing, where a map must be told which mall to draw.',
        'app/Filament/Admin/Resources/Units/Tables/UnitsTable.php' => 'A table FILTER, not a form field: nothing is written, and it is hidden outright while a mall is selected (`! PropertyField::isPinned()`) because the table is already scoped to that mall and the filter offered a list of one.',
        'app/Filament/Admin/Resources/MarketingBudgets/Schemas/MarketingBudgetForm.php' => 'A read-only DISPLAY of the budget property, never a picker: budgets are created by the billing run, the resource has no create page at all, and the field is not dehydrated. Pinning it would make it required and write-back.',
        'app/Filament/Admin/Resources/Vendors/RelationManagers/ContractsRelationManager.php' => 'Pinned by hand for a reason the shared component cannot express: a null here is a PORTFOLIO-WIDE contract covering every mall, so it must stay nullable and un-required. A relation manager also sits outside the resource-page flow, so it carries its own `->rules()` scope guard rather than a mutate hook.',
        'app/Filament/Portal/Resources/MarketingPosts/Schemas/MarketingPostForm.php' => 'The tenant PORTAL, which is tenant-scoped and not asset-scoped: a retailer trading in three malls genuinely chooses which one a shopper post is for. There is no selected property to pin to, and the submitted value is re-checked by assertTenantTradesIn().',
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
     * The FREE picker — now only the All-Properties fallback behind {@see scope()}.
     *
     * Identical scoping (it is still an `EntitySelect`, so it still cannot offer or accept a mall
     * the operator may not see) — it differs only in staying editable and allowing the blank that
     * means "every property". No screen calls this directly any more: with a mall in the switcher
     * the five {@see PORTFOLIO_LEVEL} screens render `scope()`, and this is what they fall back to
     * when there is no selected mall to scope TO, where a two-option toggle would have one live
     * option and say nothing.
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
     * The SCOPE control, for the portfolio-configuration screens in {@see PORTFOLIO_LEVEL}.
     *
     * These five screens ask a different question from every other property field in the panel.
     * Elsewhere the field means *"which mall does this record belong to?"* — one right answer, so
     * {@see make()} shows it and locks it. Here it means *"does this row apply to the whole
     * portfolio, or only to this mall?"*, and a null `asset_id` is one of the two valid answers:
     * the house wording every mall inherits, the national holiday, the operator-wide department,
     * the global posting map. All four resolvers query `whereNull('asset_id')` as their fallback
     * tier, so the blank is load-bearing and cannot simply be pinned away — doing that would make
     * the portfolio row unwritable through its own form and force the operator to retype the same
     * footer, the same Eid, the same chart once per mall.
     *
     * What was wrong was never the isolation. `free()` is an {@see EntitySelect}, so it already
     * could neither offer nor accept a mall outside the operator's visible set — measured, the
     * dropdown on a two-mall install offered exactly the mall in the switcher, and a crafted
     * payload naming the other one was refused at validation. The defect was that a SCOPE question
     * was wearing a PROPERTY PICKER: it read as "choose a mall", so an enabled dropdown looked
     * like a leak on the one screen family where it was not one.
     *
     * So the control states the two answers instead of listing malls. There is no mall to select —
     * the only property it can ever write is the one in the switcher, which is the rule the rest of
     * this class enforces by pinning.
     *
     * ## The foreign row, and why it is shown rather than hidden
     *
     * Three of the five scope their list to `null ∪ visible`, so the two options are exhaustive and
     * an edit page can only ever load a row this control can describe. **Two do not**: the posting
     * map has no `getEloquentQuery()` at all (it is `#[PortfolioShared]` accounting config), and
     * owner requests scope to the operator's ASSIGNED set rather than the selected mall — so both
     * can open a row filed against a third property. A two-option toggle would render that row as
     * "All properties", and saving would silently re-home it: a data-loss bug introduced by a UI
     * fix. Instead the record's own property is added as a third option and the whole control is
     * DISABLED, so the row shows the truth, cannot be retargeted, and survives a save unchanged.
     * That is the same answer `make()` gives on edit, for the same reason.
     *
     * The `->rules()` guard is the real gate, exactly as it is on every pinned field: the control
     * is `->dehydrated()`, so its value still arrives in the Livewire payload and a disabled input
     * is a statement of intent, never a refusal.
     */
    public static function scope(string $name = 'asset_id', ?string $allMeans = null): Radio|EntitySelect
    {
        // All-Properties plumbing: with no mall in the switcher there is nothing to scope TO, so
        // fall back to the free picker unchanged rather than offering a toggle with one live option.
        if (! self::isPinned()) {
            return self::free($name, $allMeans);
        }

        $currentId = (int) TenantScope::currentAssetId();

        /** The record's own property when it is a THIRD one — neither blank nor the selected mall. */
        $foreignIdOf = static function (?Model $record) use ($name, $currentId): ?int {
            $own = $record?->getAttribute($name);

            return filled($own) && (int) $own !== $currentId ? (int) $own : null;
        };

        return Radio::make($name)
            ->label(__('admin.fields.property_applies_to'))
            ->options(function (?Model $record) use ($currentId, $allMeans, $foreignIdOf): array {
                $options = [
                    '' => $allMeans ?? __('admin.fields.property_all'),
                    (string) $currentId => __('admin.fields.property_this_only', [
                        'property' => Asset::find($currentId)?->name ?? '',
                    ]),
                ];

                if (($foreign = $foreignIdOf($record)) !== null) {
                    $options[(string) $foreign] = __('admin.fields.property_other', [
                        'property' => Asset::find($foreign)?->name ?? (string) $foreign,
                    ]);
                }

                return $options;
            })
            ->disabled(fn (?Model $record): bool => $foreignIdOf($record) !== null)
            // A disabled input is not submitted; without this an edit of a foreign row would
            // dehydrate null and re-home it to the house default.
            ->dehydrated()
            ->default('')
            ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '')
            ->dehydrateStateUsing(fn ($state): ?int => filled($state) ? (int) $state : null)
            ->rules([
                fn (?Model $record): Closure => function (string $attribute, $value, Closure $fail) use ($currentId, $foreignIdOf, $record): void {
                    if (blank($value)) {
                        return; // the house row
                    }

                    $allowed = array_values(array_filter([$currentId, $foreignIdOf($record)]));

                    if (! in_array((int) $value, $allowed, true)) {
                        abort(403);
                    }
                },
            ]);
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

    /**
     * The scope control for a return filed per TAX REGISTRATION — a statement, not a picker.
     *
     * {@see reportScope()} pins to the mall in the switcher because a property-scoped statement
     * really is answered for one mall. The two statutory returns are not: `VatReturn::report()` and
     * `WithholdingTaxReturn::report()` each hand their service a NULL asset, deliberately and with
     * the reason written beside it — one registration covers the portfolio, and there is no
     * per-mall VAT return to file.
     *
     * So the pin named the wrong thing, which is the SAME failure `reportScope()` was built to end,
     * arriving through the other door. Measured 2026-09-04 with two malls seeded and the first one
     * in the switcher: the VAT return's filter strip read *"Property: <that mall>"* while
     * `report()['input_vat']` carried the OTHER mall's 1,400. Right figures, wrong caption — and
     * nobody re-checks a total they believe they asked for.
     *
     * **A statement rather than nothing.** Every screen in this panel is property-scoped, so a
     * return showing no scope control at all would be read as inheriting the mall in the switcher:
     * the same wrong answer, told by silence instead of by a caption.
     *
     * Keyed `assetId` like its sibling so the one gate over these strips looks both up in the same
     * place, and disabled because there is nothing here for anyone to change.
     */
    public static function registrationScope(string $name = 'assetId'): Select
    {
        return Select::make($name)
            ->label(__('admin.reports.property_scope'))
            ->placeholder(__('admin.reports.property_scope_registration'))
            ->disabled()
            ->native(false)
            ->helperText(__('admin.helpers.property_registration_scope'));
    }

    /** True when the panel is standing in one real mall — i.e. whenever the pin applies. */
    public static function isPinned(): bool
    {
        return TenantScope::currentAssetId() !== null;
    }
}
