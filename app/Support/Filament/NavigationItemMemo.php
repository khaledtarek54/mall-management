<?php

namespace App\Support\Filament;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Auth;

/**
 * One request, one answer per screen — the sidebar is built FIVE times per page render.
 *
 * ## Measured, on a page nobody would call slow
 *
 * `filament()->getNavigation()` is called by `sidebar.blade.php` and by `topbar.blade.php`, and
 * `Panel::getNavigation()` resolves a FRESH `NavigationManager` on every call, so nothing upstream
 * memoises anything. Counting one badge's own SQL on a single `/admin/{property}/leases` render:
 *
 *     the Vendors badge query ran 5x in ONE page render
 *
 * Ten screens declare `getNavigationBadge()`, each doing at least one `count(*)`, so **fifty
 * redundant COUNT queries were issued on every admin page in the panel** — 57ms of the 95ms the
 * whole sidebar cost. A badge is a hint; it should not be the most expensive thing in the chrome.
 *
 * ## Why the memo is here rather than in each badge
 *
 * A resource's badge is computed EAGERLY inside `getNavigationItems()` — `->badge(static::
 * getNavigationBadge(), …)` passes a value, not a closure — so caching the ITEM caches the badge
 * with it, and every future badge is covered by being on a screen rather than by its author
 * remembering a wrapper. That is the same reasoning that put the authorization check in
 * `AuthorizedAction` instead of in seventy-six call sites.
 *
 * ## Why not a static
 *
 * A `queue:work` daemon outlives the request, and a badge count memoised in a static would be
 * answered from whenever that worker booted. Bound `scoped()` in `AppServiceProvider`, which is a
 * singleton per request and is reset between requests under Octane — the same rule
 * `ActivityVocabulary` and `PortalBranding` follow, and for the same reason.
 *
 * ## Why the key carries the user and the property
 *
 * Neither changes inside one real request, so the key looks redundant — until a test or a console
 * command sets a different tenant on the same container instance, which is exactly where a memo
 * silently answers for the wrong mall. Cheap to be right about.
 */
final class NavigationItemMemo
{
    /** @var array<string, array<int, NavigationItem>> */
    private array $items = [];

    /**
     * The navigation items for one screen, computed at most once per request.
     *
     * @param  class-string  $screen
     * @param  callable(): array<int, NavigationItem>  $build
     * @return array<int, NavigationItem>
     */
    public function for(string $screen, callable $build): array
    {
        $key = $this->keyFor($screen);

        return $this->items[$key] ??= $build();
    }

    /** Forget everything — for a test that changes what a badge should say mid-request. */
    public function flush(): void
    {
        $this->items = [];
    }

    private function keyFor(string $screen): string
    {
        return implode('|', [
            $screen,
            Auth::id() ?? 'guest',
            Filament::getTenant()?->getKey() ?? 'no-tenant',
        ]);
    }
}
