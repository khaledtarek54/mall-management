<?php

namespace App\Support\Filament;

use App\Support\Search\OptionDisplay;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Model;

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
 * class=…>`. So the filter's own `getOptionLabelFromRecordUsing` (which the indicator reads) is
 * pointed at `RecordOption::toText()`, while the SELECT's is pointed at `toHtml()`. Same option,
 * two renderings, one definition.
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

        // The chip, and the multi-select's selected pills. Text, for the reason in the class
        // docblock — and set here rather than on the inner Select because `SelectFilter` keeps its
        // own copy for the indicator.
        $this->getOptionLabelFromRecordUsing(
            fn (Model $record): string => OptionDisplay::for($record)->toText(),
        );

        return $this;
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
        $titleAttribute ??= $this->entityModel
            ? (OptionDisplay::order($this->entityModel)[0] ?? (new $this->entityModel)->getKeyName())
            : $name;

        return parent::relationship($name, $titleAttribute, $modifyQueryUsing, $hasEmptyOption);
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
