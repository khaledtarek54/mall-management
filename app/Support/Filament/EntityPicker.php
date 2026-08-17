<?php

namespace App\Support\Filament;

use App\Support\Search\OptionDisplay;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * One picker's configuration, as a value rather than as eight positional arguments.
 *
 * ## Why this exists
 *
 * `EntitySelect::applyTo()` grew to `(Select, string, ?Closure, ?Closure, array, ?bool, bool,
 * ?Closure)` — three of those in a single day, as `->withRelations()`, `->acrossProperties()` and
 * `->suggest()` each answered a real need. Every one of them was justified; the SIGNATURE was the
 * problem. A call site reading `self::applyTo($this, $model, null, null, [], null, false, $suggest)`
 * says nothing about what it configures, and the next option makes it unreadable rather than merely
 * long.
 *
 * The object is also the honest shape of the thing: a picker IS a bundle of decisions about one
 * model, and `EntitySelectFilter` has to assemble exactly the same bundle for a `Select` it does not
 * own. Passing one value instead of eight arguments is what lets the two stay identical without
 * either of them re-listing the parts.
 *
 * Readonly, and built fresh on every application rather than mutated: `EntitySelect`'s fluent
 * setters each re-apply the whole wiring (Filament's `relationship()` overwrites the callbacks, so
 * re-application is not optional — see that class), and a config object shared across those
 * re-applications would be one more thing able to drift mid-chain.
 *
 * Deliberately no `with*()` copy-on-write helpers. Nothing needs them — the component holds the
 * mutable state and constructs this fresh each time — and a value object carrying six setters
 * nobody calls is API invented for a caller that does not exist.
 */
final class EntityPicker
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<int, string>  $relations  extra eager loads a `decorate` callback reaches for
     * @param  bool|null  $preload  null = take `OptionDisplay::PRELOAD`'s answer for this model
     */
    public function __construct(
        public readonly string $model,
        public readonly ?Closure $modifyQuery = null,
        public readonly ?Closure $decorate = null,
        public readonly array $relations = [],
        public readonly ?bool $preload = null,
        public readonly bool $spansProperties = false,
        public readonly ?Closure $suggest = null,
    ) {}

    /** Does this picker's browse list load on open, or wait for a search term? */
    public function preloads(): bool
    {
        return $this->preload ?? OptionDisplay::shouldPreload($this->model);
    }

    /** The property scope applies unless this picker is explicitly about the portfolio. */
    public function isScoped(): bool
    {
        return ! $this->spansProperties;
    }

    /**
     * The call site's narrowing, wrapped so Filament's own injection still works inside it.
     *
     * Both injections, by name AND by type: `evaluate()` matches an untyped parameter by NAME, so
     * `fn ($q) => …` would receive null and fatal on a null builder — a runtime error in one
     * dropdown that no static check sees.
     */
    public function queryModifier(object $component): ?Closure
    {
        if ($this->modifyQuery === null && $this->relations === []) {
            return null;
        }

        return function (Builder $query) use ($component): ?Builder {
            if ($this->relations !== []) {
                $query->with($this->relations);
            }

            return $this->modifyQuery
                ? $component->evaluate(
                    $this->modifyQuery,
                    ['query' => $query],
                    [Builder::class => $query, QueryBuilder::class => $query->getQuery()],
                )
                : $query;
        };
    }
}
