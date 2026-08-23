<?php

namespace App\Support;

use Filament\Resources\Resource;

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
        ?int $tableView = null,
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
