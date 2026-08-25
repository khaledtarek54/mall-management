<?php

namespace App\Support\Filament;

use App\Support\Search\OptionDisplay;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The table-filter half of {@see EntitySelect} — a "filter by tenant" dropdown that searches and
 * reads exactly like the "pick a tenant" dropdown on the form.
 *
 * These have to be two classes because `SelectFilter` is not a `Select`: it builds one internally
 * in `getFormField()`. They must not be two BEHAVIOURS, though, and that is the entire reason
 * this file exists rather than a second copy of the wiring. Before this the divergence was
 * already visible — `InvoicesTable`'s tenant filter searched `name` while `InvoiceForm`'s tenant
 * picker searched `name, legal_name, email, phone`, so the same query found a tenant on the form
 * and not in the filter directly above the list.
 *
 * ```php
 * EntitySelectFilter::make('tenant_id')
 *     ->label(__('admin.resources.tenant.singular'))
 *     ->entity(Tenant::class),
 * ```
 *
 * ## Indicators are TEXT, not markup
 *
 * Filament renders an active filter as a chip ("Tenant: Zara Home"), and a chip is a plain-text
 * surface — feed it the option markup and the operator reads their filter as a wall of `<span
 * class=…>`. So the filter's own `getOptionLabelFromRecordUsing` is pointed at
 * `RecordOption::toText()`, while the SELECT's is pointed at `toHtml()`. Same option, two
 * renderings, one definition.
 *
 * The chip itself is a THIRD rendering and does not come from either — Filament's indicator reads
 * neither callback. See `entity()`, and `indicatorLabelsFor()` for what it reads instead.
 */
class EntitySelectFilter extends SelectFilter
{
    /** @var class-string<Model>|null */
    protected ?string $entityModel = null;

    protected ?Closure $modifyOptionsQueryUsing = null;

    /**
     * @param  class-string<Model>  $model
     */
    public function entity(string $model): static
    {
        $this->entityModel = $model;

        $this->searchable();

        // The value already chosen, as the closed select renders it, and the multi-select's
        // selected pills. Text, for the reason in the class docblock — and set here rather than on
        // the inner Select because `SelectFilter` keeps its own copy and forwards it.
        $this->getOptionLabelFromRecordUsing(
            fn (Model $record): string => OptionDisplay::for($record)->toText(),
        );

        // And the CHIP, which is a separate thing that only looks like the same thing.
        //
        // Filament's own indicator does not read the callback above. `SelectFilter::setUp()`
        // plucks the relationship's TITLE ATTRIBUTE straight off its column, and for a filter
        // naming no relationship it reads `getOptions()` — which is empty here, because the
        // options come from the `EntitySelect` applied in `getFormField()` rather than from the
        // filter's own array. So the two shapes failed two different ways and neither was loud:
        // a floor chip read its `level` ("0"), and every entity filter with no `->relationship()`
        // rendered NO chip at all, leaving an applied filter with nothing in the bar to say it
        // was on or to clear it. One override answers both, from the one presenter.
        $this->indicateUsing(static function (self $filter, array $state): array {
            $values = $filter->isMultiple()
                ? array_values((array) ($state['values'] ?? []))
                : [$state['value'] ?? null];

            $labels = $filter->indicatorLabelsFor(array_values(array_filter($values, 'filled')));

            if ($labels === []) {
                return [];
            }

            $indicator = $filter->getIndicator();

            if (! $indicator instanceof Indicator) {
                $indicator = Indicator::make($indicator.': '.collect($labels)->join(', ', ' & '));
            }

            return [$indicator];
        });

        return $this;
    }

    /**
     * The plain-text names behind the submitted filter values.
     *
     * Read through `OptionDisplay::pickable()` — the SAME scoped query the picker offers from —
     * for the reason that method's docblock gives: labelling a record outside the property scope
     * is a cross-property read, and a chip is a read.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    public function indicatorLabelsFor(array $values): array
    {
        $labels = [];

        if ($this->hasEmptyRelationshipOption() && in_array(static::EMPTY_RELATIONSHIP_OPTION_KEY, $values)) {
            $labels[] = $this->getEmptyRelationshipOptionLabel();
            $values = array_values(array_filter(
                $values,
                fn ($value): bool => $value !== static::EMPTY_RELATIONSHIP_OPTION_KEY,
            ));
        }

        if ($values === [] || $this->entityModel === null) {
            return $labels;
        }

        $records = OptionDisplay::pickable($this->entityModel, $this->modifyOptionsQueryUsing)
            ->whereKey($values)
            ->get();

        foreach ($records as $record) {
            $labels[] = OptionDisplay::for($record)->toText();
        }

        return $labels;
    }

    /** @return class-string<Model>|null */
    public function getEntityModel(): ?string
    {
        return $this->entityModel;
    }

    public function modifyOptionsQuery(?Closure $callback): static
    {
        $this->modifyOptionsQueryUsing = $callback;

        return $this;
    }

    /**
     * Default the title attribute, which `SelectFilter::relationship()` requires and
     * `Select::relationship()` does not.
     *
     * A filter written `->relationship('tenant')` — the natural shape once the label comes from
     * the registry — is a TypeError on render, not a static error: the filter form is built lazily
     * inside the table's Blade, so the page 500s the first time anyone opens the filter popover
     * and every test that never opens it stays green. `TableSearchTest` found it, from four
     * tables away.
     */
    public function relationship(Closure|string|null $name, Closure|string|null $titleAttribute = null, ?Closure $modifyQueryUsing = null, Closure|bool $hasEmptyOption = false): static
    {
        $titleAttribute ??= fn (): string => $this->defaultRelationshipTitleAttribute();

        return parent::relationship($name, $titleAttribute, $modifyQueryUsing, $hasEmptyOption);
    }

    /**
     * The column `getRelationshipQuery()` orders by, resolved LAZILY — and lazily is the whole
     * point of it.
     *
     * The default used to be computed at call time from `$this->entityModel`, which is null until
     * `->entity()` runs; every call site in the panel reads `->relationship('floor')->entity(…)`,
     * so the default was always the fallback — the RELATIONSHIP NAME, used as a column. That is
     * `order by floors.floor`, a 1054 the moment anyone picked a value, on ELEVEN filters at once
     * (`tenants.tenant`, `vendors.vendor`, `units.unit`, `users.head`, …). It is invisible until
     * then: the dropdown itself is served by `EntitySelect`, and the ordering only compiles when
     * the chip goes to name the chosen record — and then, because a table remembers its filters,
     * the plain page load 500s too, which reads as a second unrelated bug.
     *
     * A closure defers the read to `getRelationshipTitleAttribute()`, which Filament already
     * evaluates, so `->entity()` and `->relationship()` compose in EITHER order — the same
     * property {@see EntitySelect} states for the field. With no entity named at all it falls to
     * the relationship's own related model, never to the relationship name.
     */
    protected function defaultRelationshipTitleAttribute(): string
    {
        $model = $this->entityModel;

        if ($model === null) {
            $relationship = $this->getRelationship();

            $model = ($relationship instanceof Relation
                ? $relationship->getRelated()
                : $relationship->getModel())::class;
        }

        return OptionDisplay::order($model)[0] ?? (new $model)->getKeyName();
    }

    /**
     * Take Filament's assembled Select and give it the entity behaviour.
     *
     * Deliberately `parent::getFormField()` + `applyTo()`, rather than rebuilding the field: that
     * method wires multiple/placeholder/relationship/default state and grows with each Filament
     * release, and a reimplementation here would silently stop tracking it.
     */
    public function getFormField(): Select
    {
        $field = parent::getFormField();

        if ($this->entityModel === null) {
            return $field;
        }

        return EntitySelect::applyTo($field, new EntityPicker(
            model: $this->entityModel,
            modifyQuery: $this->modifyOptionsQueryUsing,
            // `SelectFilter::preload()` sets a flag that `parent::getFormField()` puts on the inner
            // Select — and `applyTo()` then overwrote it with the registry's answer, so a
            // `->preload()` written on a filter did nothing at all and looked like it worked. Only
            // an explicit true is forwarded; false stays null so the registry keeps deciding, which
            // is what stops a filter drifting away from the field beside it.
            preload: $this->isPreloaded() ?: null,
        ));
    }
}
