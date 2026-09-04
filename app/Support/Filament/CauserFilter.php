<?php

namespace App\Support\Filament;

use App\Filament\Admin\Pages\ActivityLog;
use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Models\User;
use App\Services\AcceptWorkOrderService;
use App\Support\MorphMap;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Who did this?" — the ONE definition of the audit trail's most-asked filter.
 *
 * WHY IT IS SHARED. The activity feed is read on two screens: the per-record **Activities** tab
 * ({@see ActivitiesRelationManager}) and the portfolio-wide
 * **Activity log** page ({@see ActivityLog}). The tab has been filterable
 * by who acted since the day it shipped; the page never was — the same question, answerable on one
 * screen and not on the other, and the page is the one an auditor is sent to. Measured on the dev
 * database 2026-09-04: 473 activity rows, 32 of them carrying a causer, and no control on that
 * screen could narrow to them.
 *
 * The page also DELIVERS. `ActivityLog::reportCsv()` exports `getFilteredTableQuery()`, so a filter
 * mounted there is honoured by the scheduled CSV too — one seam answers both halves of the
 * complaint, rather than a screen fix and an export fix that are free to disagree.
 *
 * ## The `causer_type` clause is not optional
 *
 * `causer` is a **morphTo**, and a causer is not always a `User`: a contractor accepting a job
 * through the vendor portal is a `VendorContact` — {@see AcceptWorkOrderService},
 * whose `accept()` takes a bare `Model` and is called with `VendorScope::contact()`. Those tables
 * are independent id sequences (measured on the dev database 2026-09-04: `users` holds ids 1-6,
 * `tenant_users` holds id 1), so the obvious `where('causer_id', $id)` silently returns another
 * table's rows under the name of the person the operator picked. On an AUDIT TRAIL that is the
 * worst available failure — it does not look empty, it looks answered.
 *
 * Both halves live here so that neither screen can end up holding half the rule.
 *
 * ## It filters by USER, deliberately, and says so
 *
 * The options are `User` rows only. A `VendorContact` causer still appears in the Who column and is
 * still exported; it simply cannot be picked here, because `EntitySelectFilter` binds exactly one
 * model and a composite `type:id` option key would forfeit the folded-blob search that makes this
 * control usable. The way to that contact's actions is the unfiltered feed, or the work order's own
 * Activities tab. Widening it is a change to `EntitySelectFilter`, not to a call site.
 *
 * ## Why this is not a search box
 *
 * Every column on both tables is derived at READ time — the event word, the subject's label, the
 * rendered change set — so no stored text exists for a `LIKE` to reach, which is why the tab sets
 * `->searchable(false)` explicitly and the page declares no searchable column. Typing a name into
 * THIS control is the search: `entity()` makes it searchable against the folded `search_text` blob,
 * so a colleague's Arabic name spelled either way finds them. `TextColumn::make('causer.name')
 * ->searchable()` is NOT the alternative — `MorphTo` declares no `getRelationExistenceQuery()` and
 * inherits `BelongsTo`'s, so Filament would build the constraint against `activity_log` itself.
 */
final class CauserFilter
{
    public static function make(string $name = 'causer_id'): EntitySelectFilter
    {
        return EntitySelectFilter::make($name)
            ->label(__('admin.filters.causer'))
            ->entity(User::class)
            ->query(fn (Builder $query, array $data): Builder => $query->when(
                $data['value'] ?? null,
                fn (Builder $q, $causerId): Builder => $q
                    ->where('causer_id', $causerId)
                    ->where('causer_type', MorphMap::alias(User::class)),
            ));
    }
}
