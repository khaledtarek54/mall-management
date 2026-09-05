<?php

namespace App\Support;

use Filament\Resources\Resource;
use Illuminate\Support\Facades\Route;
use LogicException;

/**
 * **The one way to build a deep link into a resource's table.**
 *
 * A dashboard card that says "9 overdue invoices" is only useful if clicking it lands the
 * operator on those nine rows. That is entirely a matter of getting the query string right,
 * and the query string is not the one you would guess: Filament publishes the table
 * component's state under *aliases*, declared on `Filament\Resources\Pages\ListRecords` —
 *
 *   #[Url(as: 'filters')]  public ?array  $tableFilters;
 *   #[Url(as: 'sort')]     public ?string $tableSort;
 *   #[Url(as: 'search')]   public          $tableSearch;
 *   #[Url(as: 'tab')]      public ?string $activeTab;
 *
 * — so `?filters[...]` binds and `?tableFilters[...]` is *ignored*. Both produce a valid URL,
 * both return HTTP 200, and the wrong one silently drops the operator on the unfiltered list.
 * Nine links shipped that way (the whole leasing pipeline, the whole compliance panel, and the
 * option-window card) because the property name is the obvious thing to write and nothing ever
 * disagreed with it.
 *
 * This class exists so that mistake cannot be written down. The parameters are named and typed,
 * so there is no argument that could carry `tableFilters` — the caller states *what* they want
 * filtered and this decides what the URL is called.
 *
 * `ResourceLinkConformanceTest` enforces two things on top:
 *   1. nothing under `app/Filament` builds an index link with a raw `filters`/`sort` array
 *      itself — it has to come through here;
 *   2. every link this produces actually resolves on the destination: the filter name exists
 *      on that table, the sort column exists AND is `->sortable()` (Filament drops the sort
 *      silently otherwise), and loading the page really does narrow the record set.
 *
 * @see ResourceLink::QUERY_KEYS
 */
final class ResourceLink
{
    /**
     * Every query-string key a Filament v4 list page actually binds, and the component
     * property behind it. Anything not in this list is inert — it will not error, it will
     * just do nothing.
     *
     * @var array<string, string>
     */
    public const QUERY_KEYS = [
        'filters' => 'tableFilters',
        'sort' => 'tableSort',
        'search' => 'tableSearch',
        'tab' => 'activeTab',
        'grouping' => 'tableGrouping',
        'reordering' => 'isTableReordering',
    ];

    /**
     * The v3 property names, kept only so the conformance gate can name the mistake precisely
     * when it reappears. Never emit these.
     *
     * @var array<int, string>
     */
    public const DEAD_KEYS = ['tableFilters', 'tableSort', 'tableSearch', 'tableGrouping', 'activeTab'];

    /**
     * A link to a resource's CREATE page, carrying prefill in the query string.
     *
     * **Why this exists rather than a bare `Resource::getUrl('create', [...])`.** Filament resolves
     * a parameter array against the destination ROUTE first: any key that names a route parameter
     * is substituted into the PATH, and only what is left over becomes a query string. Every
     * resource route in this panel carries the tenancy parameter `tenant`, so
     * `getUrl('create', ['tenant' => $id])` does not prefill a tenant — it puts that id where the
     * mall's slug belongs and the page 404s.
     *
     * That has shipped twice: `CreatePayment` in August, whose prefill could therefore never fire,
     * and the tenant 360's compliance tab on 2026-09-05, whose Record-violation button was dead
     * from the moment it landed. Both files carried a comment warning about it — the second was
     * written by someone who had just read the first — which is how a trap stops earning another
     * paragraph and starts earning a seam.
     *
     * So the mistake is REFUSED here rather than described. The convention for the value the
     * collision was reaching for is a `for_` prefix (`for_tenant`), read back by the create page's
     * own `fillForm()`.
     *
     * **`LogicException`, deliberately NOT a `DomainException`.** Every call site is a `->url()`
     * closure, so this fires at RENDER time — once per table row — not where the line was typed.
     * A `DomainException` in this codebase is an operator refusal: `bootstrap/app.php` renders it
     * as a toast and a redirect back, and `dontReport()`s it. That would show an operator an
     * English sentence about route parameters, bounce their page, and keep the whole thing out of
     * Sentry. This is a developer error and belongs in the 500 that gets reported.
     *
     * @param  class-string<resource>  $resource
     * @param  array<string, mixed>  $query  prefill for the create page's `fillForm()`
     *
     * @throws LogicException when a key would be substituted into the path instead
     */
    public static function create(string $resource, array $query = []): string
    {
        if ($query !== []) {
            self::assertNoRouteParameterCollision(
                $resource::getRouteBaseName().'.create',
                $query,
                class_basename($resource),
            );
        }

        return $resource::getUrl('create', $query);
    }

    /**
     * A link to a standalone Filament PAGE, carrying its parameters in the query string.
     *
     * The resource twin of {@see create()}, and it exists for the same reason:
     * `Page::getUrl(array $parameters)` has byte-identical `$parameters['tenant'] ??= …`
     * semantics, so a page parameter named after a route parameter is substituted into the PATH
     * and the link 404s exactly as a create link does.
     *
     * The call site that makes this worth a seam rather than a comment is
     * `ReportParameters::urlFor()`, which builds its array from whatever parameters a report
     * DECLARES — so the collision is not written by anybody, it appears the day a report declares
     * a parameter with an unlucky name.
     *
     * @param  class-string  $page
     * @param  array<string, mixed>  $query
     *
     * @throws LogicException when a key would be substituted into the path instead
     */
    public static function page(string $page, array $query = []): string
    {
        if ($query !== []) {
            self::assertNoRouteParameterCollision($page::getRouteName(), $query, class_basename($page));
        }

        return $page::getUrl($query);
    }

    /**
     * Refuse a query key that the router would substitute into the path.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws LogicException
     */
    private static function assertNoRouteParameterCollision(string $routeName, array $query, string $subject): void
    {
        $route = Route::getRoutes()->getByName($routeName);

        // A name we cannot resolve is not a licence to guess — but it is also not silently fine.
        // `getUrl()` itself throws `RouteNotFoundException` a line later, so the guard degrading
        // to "nothing reserved" cannot mask a live link: an unresolvable route has no working
        // call site to protect.
        if ($route === null) {
            return;
        }

        $collisions = array_intersect(array_keys($query), $route->parameterNames());

        if ($collisions === []) {
            return;
        }

        throw new LogicException(sprintf(
            '%s: [%s] name route parameter(s) of %s, so they would be substituted into the PATH '
            .'rather than the query string and the link would 404. Prefix the value (for_%s) and '
            .'read it on the destination page.',
            $subject,
            implode(', ', $collisions),
            $routeName,
            reset($collisions),
        ));
    }

    /**
     * A link to a resource's list page, pre-filtered and pre-sorted.
     *
     * @param  class-string<resource>  $resource
     * @param  array<string, mixed>  $filters  Filter name => state, e.g.
     *                                         `['status' => ['value' => 'active']]` for a SelectFilter,
     *                                         `['overdue_only' => ['isActive' => true]]` for a Filter.
     * @param  ?string  $sort  `column:direction`, e.g. `due_date:asc`. The column must be
     *                         `->sortable()` on the destination table or Filament ignores this.
     */
    public static function index(
        string $resource,
        array $filters = [],
        ?string $sort = null,
        ?string $search = null,
        ?string $tab = null,
        int|string|null $tableView = null,
    ): string {
        $parameters = [];

        if ($filters !== []) {
            $parameters['filters'] = $filters;
        }

        if ($sort !== null) {
            $parameters['sort'] = $sort;
        }

        if ($search !== null) {
            $parameters['search'] = $search;
        }

        if ($tab !== null) {
            $parameters['tab'] = $tab;
        }

        // A saved view's COLUMN layout is too big for a query string and Filament binds none of it
        // to the URL, so the link names the view and the page reads its columns back (EG-32). It is
        // an id, not a layout: what the reader sees is rebuilt from their own table.
        //
        // `string` as well as `int` for the ONE non-id value: `tableView=none` is how the saved-view
        // menu asks for the plain list when a default is set. A link with an empty query string is
        // indistinguishable from a bare page load, which is exactly what the default's mount hook
        // redirects — so the reset has to say something. `bootedSavesTableViews()` ignores anything
        // non-numeric, so it applies no columns and the request simply stops being "nothing asked".
        if ($tableView !== null) {
            $parameters['tableView'] = $tableView;
        }

        return $resource::getUrl('index', $parameters);
    }

    /**
     * Shorthand for the commonest shape: a toggle/checkbox `Filter::make('x')`, whose state is
     * `['isActive' => true]`. Spelling that array out at 8 call sites is 8 chances to typo it.
     *
     * @param  class-string<resource>  $resource
     */
    public static function indexWhere(string $resource, string $filter, ?string $sort = null): string
    {
        return self::index($resource, [$filter => ['isActive' => true]], $sort);
    }

    /**
     * Shorthand for a `SelectFilter::make('x')`, whose state is `['value' => …]`.
     *
     * @param  class-string<resource>  $resource
     */
    public static function indexSelect(string $resource, string $filter, string|int $value, ?string $sort = null): string
    {
        return self::index($resource, [$filter => ['value' => $value]], $sort);
    }
}
