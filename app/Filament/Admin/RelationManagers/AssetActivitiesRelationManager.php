<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\Asset;
use App\Models\Floor;
use App\Models\RentableItem;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * A property's Activity Log, widened to the things that MAKE UP the property.
 *
 * Reported by the tester: adding a staff member, an owner, a unit, a parking bay or a floor to a
 * property did not appear in that property's Activity Log. The stock relation manager reads
 * `activitiesAsSubject` — rows whose subject is the Asset ROW — and creating a unit files its row
 * against the UNIT, so the tab showed changes to the property's own fields and nothing else.
 *
 * Two different causes sat behind one symptom, and both are fixed:
 *
 *  - **Staff and owners logged nothing at all**, anywhere. `attach()` writes through the query
 *    builder and fires no model event. `App\Support\PropertyRoster` records those against the ASSET,
 *    so they need no widening here.
 *  - **Units logged nothing either** — `Unit` was audited nowhere in the system until 2026-09-05.
 *    Floors and rentable items were already audited; they were simply filed under their own subject.
 *
 * **CHILDREN is a short, explicit list and must stay one.** Almost every model in this system is
 * `#[PropertyOwned]` — leases, invoices, payments, work orders — so deriving this from
 * `PropertyIsolation` would put the entire operational history of the mall on one tab, which is a
 * different feature (and an unreadable one). These three are the property's SPATIAL make-up: the
 * things a floor plan is drawn from, which is what the card asked about. Adding a fourth is a
 * deliberate decision, not a default.
 */
class AssetActivitiesRelationManager extends ActivitiesRelationManager
{
    /**
     * The models whose activity belongs to the PROPERTY's history rather than only their own.
     *
     * @var array<int, class-string>
     */
    public const CHILDREN = [
        Unit::class,
        Floor::class,
        RentableItem::class,
    ];

    public function getTableQuery(): Builder
    {
        /** @var Asset $asset */
        $asset = $this->getOwnerRecord();

        // Built from Activity directly rather than from `parent::getTableQuery()`, which returns
        // NULL on a relation manager — Filament falls back to the relationship, whose
        // `subject = this asset` constraint is exactly what has to widen here. Composing an
        // `orWhere` onto an already-constrained query would bind AND-before-OR and let the child
        // branch escape the property scope entirely, which is the trap this codebase keeps
        // recording; stating both sides inside one closure is what keeps them grouped.
        return Activity::query()
            ->where(function (Builder $query) use ($asset): void {
                $query
                    ->where(fn (Builder $q) => $q
                        ->where('subject_type', $asset->getMorphClass())
                        ->where('subject_id', $asset->getKey()))
                    ->orWhere(fn (Builder $q) => $q->where(function (Builder $q) use ($asset): void {
                        foreach (self::CHILDREN as $child) {
                            /** @var Model $model */
                            $model = new $child;

                            $q->orWhere(fn (Builder $inner) => $inner
                                ->where('subject_type', $model->getMorphClass())
                                ->whereIn('subject_id', $child::query()
                                    ->withoutGlobalScopes()
                                    ->where('asset_id', $asset->getKey())
                                    ->select('id')));
                        }
                    }));
            });
    }
}
