<?php

namespace App\Support;

use Closure;
// Filament 4 moved the list-page tab into the schemas package — it is
// Schemas\Components\Tabs\Tab, NOT the Filament 3 ListRecords\Tab.
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Status tabs for a resource's List page — the worklist strip across the top.
 *
 * Every module here has a lifecycle (draft → issued → paid, open → in progress
 * → done), and until now the only way to answer "what do I still owe work on?"
 * was to open the filter panel and pick a status by hand, every single time.
 * Tabs put the two or three states an operator actually works one click away,
 * and the badge says how many are waiting without them looking.
 *
 * Usage on a ListRecords page:
 *
 *     protected function getTabs(): array
 *     {
 *         return StatusTabs::build(InvoiceResource::class, [
 *             'all'     => ['label' => __('admin.tabs.all')],
 *             'overdue' => ['label' => ..., 'statuses' => ['overdue'], 'badge' => true, 'color' => 'danger'],
 *         ]);
 *     }
 *
 * Design notes:
 *
 * - The badge count is produced by running the tab's OWN modifier over the
 *   resource's own getEloquentQuery(). That matters twice over: the count can
 *   never drift from what the tab actually shows, and it inherits the
 *   resource's property scoping — a badge is not a place to leak another
 *   mall's row count.
 *
 * - Badges are opt-in per tab ('badge' => true) because each one costs a COUNT
 *   on every page load. Put them on the tabs that mean "work is waiting"
 *   (overdue, unpaid, open), not on All / Paid / Closed.
 *
 * - Tabs are additive to the filters, not a replacement: a tab narrows the
 *   base query, and the filter panel still applies on top.
 */
class StatusTabs
{
    /**
     * @param  class-string  $resource  the Filament resource (for the scoped badge query)
     * @param  array<string, array{
     *     label: string,
     *     statuses?: array<int, string>,
     *     query?: Closure,
     *     badge?: bool,
     *     color?: string,
     *     icon?: string,
     * }>  $spec
     * @return array<string, Tab>
     */
    public static function build(string $resource, array $spec, string $column = 'status'): array
    {
        $tabs = [];

        foreach ($spec as $key => $config) {
            $modifier = self::modifier($config, $column);

            $tab = Tab::make($config['label']);

            if ($modifier !== null) {
                $tab->modifyQueryUsing($modifier);
            }

            if ($config['icon'] ?? null) {
                $tab->icon($config['icon']);
            }

            if ($config['badge'] ?? false) {
                // Count through the resource's scoped query with this tab's own
                // modifier — so badge and contents cannot disagree, and neither
                // escapes the property scope.
                $tab->badge(function () use ($resource, $modifier): int {
                    $query = $resource::getEloquentQuery();

                    if ($modifier !== null) {
                        $modifier($query);
                    }

                    return $query->count();
                });

                if ($config['color'] ?? null) {
                    $tab->badgeColor($config['color']);
                }
            }

            $tabs[$key] = $tab;
        }

        return $tabs;
    }

    /**
     * A tab narrows by an explicit closure, or by a status whitelist, or (the
     * "All" tab) not at all.
     *
     * @param  array<string, mixed>  $config
     */
    private static function modifier(array $config, string $column): ?Closure
    {
        if (isset($config['query'])) {
            return $config['query'];
        }

        if (! empty($config['statuses'])) {
            $statuses = $config['statuses'];

            return fn (Builder $query) => $query->whereIn($column, $statuses);
        }

        return null;
    }
}
