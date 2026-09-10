<?php

namespace App\Filament\Admin\RelationManagers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * A record's Activity tab covers the record AND the things that MAKE IT UP.
 *
 * Filament's `activitiesAsSubject` relation reads rows whose subject is the record ROW, so a child
 * created under it files its activity against ITSELF and the parent's tab never mentions it. That
 * was reported twice — once for a property (units, floors, parking, staff, owners) and once for a
 * tenant (portal logins, leases, documents) — which is why the query is here rather than copied a
 * second time.
 *
 * **CHILDREN is a short, explicit list per host and must stay one.** Almost everything in this
 * system hangs off a property or a tenant, so deriving the set from `PropertyIsolation` or from
 * "has a `tenant_id`" would put the entire operational history — invoices, payments, credit notes,
 * work orders — on one tab, which is a different feature and an unreadable one. What belongs here is
 * what CONSTITUTES the record: a property's spatial make-up, a tenant's relationship with the
 * operator. Adding a fourth is a deliberate decision, not a default.
 *
 * The host declares two things: `activityChildren()` (model class => the foreign key it hangs off
 * the host by) and nothing else — the parent's own rows are always included.
 */
trait ShowsItsChildrensActivity
{
    /**
     * The models whose activity belongs to this record's history rather than only their own.
     *
     * @return array<class-string, string> model => foreign key on that model
     */
    abstract protected function activityChildren(): array;

    public function getTableQuery(): Builder
    {
        /** @var Model $owner */
        $owner = $this->getOwnerRecord();

        // Built from Activity directly rather than from `parent::getTableQuery()`, which returns
        // NULL on a relation manager — Filament falls back to the relationship, whose
        // `subject = this record` constraint is exactly what has to widen here. Composing an
        // `orWhere` onto an already-constrained query would bind AND-before-OR and let the child
        // branch escape the scope entirely, which is the trap this codebase keeps recording;
        // stating both sides inside one closure is what keeps them grouped.
        return Activity::query()
            ->where(function (Builder $query) use ($owner): void {
                $query
                    ->where(fn (Builder $q) => $q
                        ->where('subject_type', $owner->getMorphClass())
                        ->where('subject_id', $owner->getKey()))
                    ->orWhere(fn (Builder $q) => $q->where(function (Builder $q) use ($owner): void {
                        foreach ($this->activityChildren() as $child => $foreignKey) {
                            /** @var Model $model */
                            $model = new $child;

                            $q->orWhere(fn (Builder $inner) => $inner
                                ->where('subject_type', $model->getMorphClass())
                                // `withoutGlobalScopes()` because this is a SUBQUERY of ids the tab
                                // has already scoped by naming the owner — leaving the panel's
                                // tenancy scope on it would narrow a child by whichever property
                                // happened to be selected, which for a tenant's leases is wrong.
                                ->whereIn('subject_id', $child::query()
                                    ->withoutGlobalScopes()
                                    ->where($foreignKey, $owner->getKey())
                                    ->select('id')));
                        }
                    }));
            });
    }
}
