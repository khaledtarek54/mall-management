<?php

namespace App\Support\Filament;

use App\Support\Search\OptionDisplay;
use App\Support\Search\RecordOption;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The Select every picker that chooses a RECORD is built from.
 *
 * `Select::make('tenant_id')->relationship('tenant', 'name')->searchable()` is one line and it is
 * wrong in four ways at once, none of which fails loudly:
 *
 *   1. **It searches one column.** `name`, raw. Not the tenant's phone, not their tax ID, not
 *      their trade name, not their commercial register — all of which are already in the record's
 *      `search_text` blob and already findable from the top search bar and from the tenant list.
 *      The dropdown was the one surface that could not read them.
 *   2. **It searches UNFOLDED.** So «شركه» does not find «شركة», `INV2026` does not find
 *      `INV-2026`, and `01001234567` does not find a phone stored as `+20 100 123 4567`. The
 *      whole point of `App\Support\Search\SearchText` is that both sides go through it; a raw
 *      `LIKE` on a raw column folds neither.
 *   3. **It shows one column.** Three tenants called Zara render as three identical rows.
 *   4. **It fails silently.** Every one of the above produces an empty dropdown or an ambiguous
 *      one, which is indistinguishable from "there is no such record" — so it never gets reported
 *      as a bug, it gets worked around by leaving the form.
 *
 * `EntitySelect::make('tenant_id')->entity(Tenant::class)` fixes all four from one registry:
 *
 * ```php
 * EntitySelect::make('tenant_id')
 *     ->label(__('admin.resources.tenant.singular'))
 *     ->entity(Tenant::class)
 *     ->modifyOptionsQuery(fn (Builder $query) => $query->where('status', 'active'))
 *     ->required(),
 *
 * // Relationship-backed (a multi-select, or anywhere Filament must save through the relation):
 * EntitySelect::make('supervisors')
 *     ->entity(User::class)
 *     ->relationship('supervisors')
 *     ->multiple(),
 * ```
 *
 * ## Order does not matter, and that took an override
 *
 * `Select::relationship()` installs its OWN `getSearchResultsUsing()`, `options()` and
 * `getOptionLabelUsing()` — so a chain that calls `->entity()` first and `->relationship()` second
 * would have silently reverted to stock Filament behaviour, which is precisely the failure this
 * class exists to prevent and precisely the one that looks like it is working. `relationship()`
 * is therefore overridden to re-apply the entity wiring after delegating to the parent, and
 * `entity()` applies it directly. Either order gives the same component.
 *
 * ## The property scope is a WRITE guard, not just a filter
 *
 * Every query here runs through `OptionDisplay::pickable()`, including the lookup that renders an
 * already-chosen value. That is not decoration: Filament validates a Select by asking it to
 * resolve the submitted value's label and rejecting the value if it cannot
 * (`Select::getInValidationRuleValues()`). Resolving labels unscoped would turn every entity
 * select in the panel into an accepted cross-property foreign key. See `OptionDisplay::pickable()`.
 */
class EntitySelect extends Select
{
    /** @var class-string<Model>|null */
    protected ?string $entityModel = null;

    protected ?Closure $modifyOptionsQueryUsing = null;

    protected ?Closure $decorateOptionUsing = null;

    /** @var array<int, string> */
    protected array $extraOptionRelations = [];

    /**
     * Has the wiring been applied once already?
     *
     * `modifyOptionsQuery()`, `decorateOption()` and `withRelations()` each re-apply it, so the
     * FIRST application takes the registry's preload answer and every later one preserves whatever
     * the component currently holds. Without that distinction a `->preload()` written anywhere
     * before those setters would be silently reset by the next call in the chain — the class of bug
     * this component exists to remove, not to introduce.
     */
    protected bool $entityWiringApplied = false;

    protected bool $spansProperties = false;

    /**
     * Re-entrancy guard.
     *
     * `applyTo()` calls `->preload()` on the component, and `preload()` is overridden to re-apply
     * the wiring — so without this the two call each other until the process dies. It does not
     * fail as an error: the page simply never finishes rendering.
     */
    protected bool $applyingEntityWiring = false;

    /**
     * Bind this select to a model, and take everything else from the registry.
     *
     * @param  class-string<Model>  $model
     */
    public function entity(string $model): static
    {
        $this->entityModel = $model;

        return $this->applyEntityBehaviour();
    }

    /** @return class-string<Model>|null */
    public function getEntityModel(): ?string
    {
        return $this->entityModel;
    }

    /**
     * Narrow the pickable set further — composed with (never replacing) the property scope.
     *
     * Evaluated through Filament's own injection, so a callback may take `Get $get` and depend on
     * another field: the unit picker that must only offer units in the property chosen higher up
     * the form is written here, not by rebuilding the query.
     */
    public function modifyOptionsQuery(?Closure $callback): static
    {
        $this->modifyOptionsQueryUsing = $callback;

        return $this->applyEntityBehaviour();
    }

    /**
     * Add a screen-specific fact to every option, without moving it into the registry.
     *
     * The callback receives `(RecordOption $option, Model $record)` and returns a `RecordOption` —
     * usually `$option->append(...)` or `$option->withBadge(...)`. It exists for the warning that
     * is true on ONE picker: the lease form flags a unit encumbered by an outstanding option,
     * which is what an operator letting the space needs and is noise on the work-order form.
     *
     * Escaping still happens in `RecordOption::toHtml()`, so a decoration cannot smuggle markup
     * in — which is the whole reason this is a hook returning a value object rather than a
     * `getOptionLabelFromRecordUsing()` returning a string.
     */
    public function decorateOption(?Closure $callback): static
    {
        $this->decorateOptionUsing = $callback;

        return $this->applyEntityBehaviour();
    }

    /**
     * Eager-load more than the registry does, for what a decoration reaches.
     *
     * Without this a `decorateOption()` that touches a relation is an N+1 per open dropdown —
     * quiet on demo data, one query per option in production.
     *
     * @param  array<int, string>  $relations
     */
    public function withRelations(array $relations): static
    {
        $this->extraOptionRelations = $relations;

        return $this->applyEntityBehaviour();
    }

    /**
     * Offer records from every property, not only the one being worked in.
     *
     * A narrow, deliberate exception. `OptionDisplay::scope()` answers "which records belong to the
     * property this operator is in", and that is right for the ~130 pickers that fill in a record's
     * own fields. It is wrong for the handful that are ABOUT the portfolio: the user form's
     * property-assignment field grants access across malls and defaults to all of them, so scoping
     * it to the current property would make the form's own default fail its own validation.
     *
     * Do not reach for this to make a picker "show more". It removes the clause that is also the
     * write guard (see `OptionDisplay::pickable()`), so a call site using it owns the question of
     * what the submitted value is allowed to be. State the reason at the call site.
     */
    public function acrossProperties(): static
    {
        $this->spansProperties = true;

        return $this->applyEntityBehaviour();
    }

    /**
     * Browse the whole (narrowed) set on open, instead of waiting for a search term.
     *
     * For the pickers where BROWSING is the flow rather than looking something up — a leasing
     * officer opens the unit picker to see what is vacant. Overridden rather than inherited so it
     * RE-APPLIES the wiring: whether the options list is a closure or a static empty array is
     * decided from the preload state, so calling Filament's `preload()` without re-applying would
     * leave the component configured for the opposite answer.
     */
    public function preload(bool|Closure $condition = true): static
    {
        parent::preload($condition);

        return $this->applyEntityBehaviour();
    }

    /**
     * Re-apply the entity wiring after Filament's own relationship setup has overwritten it.
     *
     * @see static::applyEntityBehaviour() for why this exists
     */
    public function relationship(string|Closure|null $name = null, string|Closure|null $titleAttribute = null, ?Closure $modifyQueryUsing = null, bool $ignoreRecord = false): static
    {
        // A real column, because Filament reaches for the title attribute in paths this class does
        // not override (`fillStateFromRelationship`, the relationship ordering fallback). Passing
        // null there leaves `str_contains(null, '->')` waiting for someone to touch one of them.
        $titleAttribute ??= $this->entityModel
            ? (OptionDisplay::order($this->entityModel)[0] ?? (new $this->entityModel)->getKeyName())
            : null;

        parent::relationship($name, $titleAttribute, $modifyQueryUsing, $ignoreRecord);

        return $this->entityModel ? $this->applyEntityBehaviour() : $this;
    }

    protected function applyEntityBehaviour(): static
    {
        if ($this->entityModel === null || $this->applyingEntityWiring) {
            return $this;
        }

        $this->applyingEntityWiring = true;

        try {
            self::applyTo(
                $this,
                $this->entityModel,
                $this->modifyOptionsQueryUsing,
                $this->decorateOptionUsing,
                $this->extraOptionRelations,
                // First time: take the registry's answer. Every re-application: keep what the component
                // holds now, which is either that answer or a `->preload()` the call site added since.
                $this->entityWiringApplied ? $this->isPreloaded() : null,
                $this->spansProperties,
            );
        } finally {
            $this->applyingEntityWiring = false;
        }

        $this->entityWiringApplied = true;

        return $this;
    }

    /**
     * Everything an entity picker gets, from the registry — applied to any `Select`.
     *
     * Static and taking the component rather than living only on `$this`, because a table filter
     * cannot be an `EntitySelect`: `SelectFilter` builds its own plain `Select` inside
     * `getFormField()`. `EntitySelectFilter` calls this on that instance, so a filter dropdown and
     * a form dropdown over the same model are the same dropdown — same fold, same subtitle, same
     * scope. Two implementations would have been two behaviours within a week.
     *
     * Named `applyTo` and not `configure` for a mundane, fatal reason: `Component::configure()`
     * already exists as an instance method on every Filament component, and redeclaring it static
     * is a class-load error — i.e. every page in the panel goes down, not just the ones with a
     * picker. (`render()` is the same trap one method below.)
     *
     * @param  class-string<Model>  $model
     */
    public static function applyTo(
        Select $select,
        string $model,
        ?Closure $modifyOptionsQuery = null,
        ?Closure $decorateOption = null,
        array $extraRelations = [],
        ?bool $preload = null,
        bool $spansProperties = false,
    ): Select {
        $modifier = ($modifyOptionsQuery === null && $extraRelations === [])
            ? null
            : function (Builder $query) use ($select, $modifyOptionsQuery, $extraRelations): ?Builder {
                if ($extraRelations !== []) {
                    $query->with($extraRelations);
                }

                return $modifyOptionsQuery
                    // BOTH injections. Filament's `evaluate()` matches an untyped parameter by NAME,
                    // so `fn ($q) => …` would receive null and the callback would fatal on a null
                    // builder — a runtime error on one dropdown, in one form, that no static check
                    // sees. The typed injection makes `fn (Builder $q)` work regardless of what the
                    // author called it.
                    ? $select->evaluate(
                        $modifyOptionsQuery,
                        ['query' => $query],
                        [Builder::class => $query, QueryBuilder::class => $query->getQuery()],
                    )
                    : $query;
            };

        $decorate = $decorateOption === null
            ? null
            // The option's model is injected BY TYPE only, never by the name `record`. Filament's
            // `evaluate()` resolves a named injection before a typed one, so naming it `record`
            // would shadow the form's own `$record` — and a decorator on the lease form legitimately
            // wants both (the Unit being offered AND the Lease being edited). By type they coexist:
            // `fn (RecordOption $option, Unit $unit, ?Lease $record)` gets all three.
            : fn (RecordOption $option, Model $model): RecordOption => $select->evaluate(
                $decorateOption,
                ['option' => $option],
                [Model::class => $model, $model::class => $model],
            ) ?? $option;

        $preloads = $preload ?? OptionDisplay::shouldPreload($model);

        $select
            ->allowHtml()
            // A non-empty column list is what makes Filament treat this as SERVER-searched. With
            // it blank, `hasDynamicSearchResults()` is false and the browser quietly filters the
            // options it already has — against the option HTML, since that is all it holds.
            ->searchable(OptionDisplay::searchColumns($model))
            ->optionsLimit(OptionDisplay::LIMIT)
            // Filament's default is a full second, which reads as a broken box: the operator
            // finishes typing, sees nothing, and starts deleting characters. 400ms matches the
            // debounce already set on the panel's global search bar.
            ->searchDebounce(400)
            // `null` = take the registry's answer; a bool = the call site said so with `->preload()`.
            // For the pickers where BROWSING is the flow rather than looking something up: a leasing
            // officer opens the unit picker to see what is vacant, and an empty list waiting for a
            // search term is a worse answer than a scrollable one.
            ->preload($preloads)
            ->searchPrompt(fn (): string => OptionDisplay::searchPrompt($model))
            ->searchingMessage(fn (): string => __('admin.search.option.searching'))
            ->noSearchResultsMessage(fn (): string => __('admin.search.option.no_results'))
            ->loadingMessage(fn (): string => __('admin.search.option.searching'));

        $select->getSearchResultsUsing(
            fn (string $search): array => self::toLabels(OptionDisplay::search(
                model: $model,
                search: $search,
                modifyQuery: $modifier,
                limit: $select->getOptionsLimit(),
                scoped: ! $spansProperties,
            ), $decorate),
        );

        // Preloading: the whole (narrowed) set, as a closure. NOT preloading: a STATIC empty array,
        // and the difference is visible to the operator rather than internal. Filament reads a
        // closure as `hasDynamicOptions`, and an empty dynamic list makes its JS render
        // "No options available." — on a picker whose entire job is to be typed into. A static
        // empty list instead shows the search prompt, which is the sentence that tells them what
        // they may type. (Found by opening one in a browser; no test would have said a word.)
        $preloads
            ? $select->options(fn (): array => self::toLabels(
                OptionDisplay::options($model, $modifier, scoped: ! $spansProperties),
                $decorate,
            ))
            : $select->options([]);

        $select->getOptionLabelUsing(
            fn ($value): ?string => self::toLabel(
                OptionDisplay::labels($model, [$value], $modifier, scoped: ! $spansProperties),
                $decorate,
            ),
        );

        $select->getOptionLabelsUsing(
            fn (array $values): array => self::toLabels(
                OptionDisplay::labels($model, $values, $modifier, scoped: ! $spansProperties),
                $decorate,
            ),
        );

        $select->getOptionLabelFromRecordUsing(
            fn (Model $record): string => ($decorate
                ? $decorate(OptionDisplay::for($record), $record)
                : OptionDisplay::for($record))->toHtml(),
        );

        return $select;
    }

    /**
     * @param  array<int|string, array{0: RecordOption, 1: Model}>|array<int|string, RecordOption>  $options
     * @return array<int|string, string>
     */
    // NOT `render()`: `ViewComponent::render()` already exists as an instance method, and a
    // static redeclaration is a fatal at class-load time — which is to say every page in the
    // panel, not just the ones with a picker on them.
    protected static function toLabels(array $options, ?Closure $decorate = null): array
    {
        return array_map(
            fn (array $entry): string => self::decorated($entry, $decorate)->toHtml(),
            $options,
        );
    }

    /** @param array<int|string, array{0: RecordOption, 1: Model}> $options */
    protected static function toLabel(array $options, ?Closure $decorate = null): ?string
    {
        $first = reset($options);

        return $first === false ? null : self::decorated($first, $decorate)->toHtml();
    }

    /** @param array{0: RecordOption, 1: Model} $entry */
    protected static function decorated(array $entry, ?Closure $decorate): RecordOption
    {
        [$option, $record] = $entry;

        return $decorate ? $decorate($option, $record) : $option;
    }
}
