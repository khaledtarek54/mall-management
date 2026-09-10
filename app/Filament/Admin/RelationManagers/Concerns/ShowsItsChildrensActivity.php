<?php

namespace App\Filament\Admin\RelationManagers\Concerns;

use App\Support\Attributes\PropertyItself;
use App\Support\PropertyIsolation;
use App\Support\PropertyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
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

    /**
     * **A CHILD'S ACTIVITY IS STILL THAT CHILD'S MALL'S.**
     *
     * The subquery names the OWNER, which is enough when the owner is the property itself — an
     * `Asset`'s units and floors are its own by construction, and narrowing them to the SELECTED
     * mall would empty the property page of every mall but the active one. It is NOT enough when
     * the owner is a portfolio-shared master: a `Tenant` trades in several malls, so its leases do
     * too, and an operator holding one mall was reading the other's lease history off this tab —
     * measured, `subject=lease#2` where lease 2 is in a mall they do not hold. That is the
     * invariant CLAUDE.md already states for the activity LOG (*"a feed that spans every mall is
     * readable only by someone entitled to every mall"*), reached through a different door.
     *
     * The old comment here argued that narrowing a tenant's leases by the selected property is
     * wrong. `TenantLeasesRelationManager` has narrowed exactly those leases since 2026-07, with a
     * regression test — so that argument was already contradicted one tab away; what it was really
     * defending is dropping FILAMENT's tenancy scope, which is a different thing and still right.
     *
     * Both halves are DERIVED from the register, so a new child or a new owner is right by being
     * what it is: a child that is not `#[PropertyOwned]` has no property to narrow by (a
     * `TenantUser` is the tenant's, in every mall), and an owner that IS the property needs none.
     */
    protected function scopeChild(Builder $query, string $child): Builder
    {
        $owner = $this->getOwnerRecord();

        if (! PropertyIsolation::isOwned($child) || static::ownerIsTheProperty($owner::class)) {
            return $query;
        }

        return PropertyScope::apply($query, $child, static::class);
    }

    protected static function ownerIsTheProperty(string $model): bool
    {
        return (new ReflectionClass($model))->getAttributes(PropertyItself::class) !== [];
    }

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
                                // `withoutGlobalScopes()` drops FILAMENT's tenancy scope, which
                                // keys on whichever property is selected and is meaningless on a
                                // child reached through its owner. It does NOT mean unscoped: see
                                // `scopeChild()` — the property constraint that belongs here is the
                                // CHILD's own, read off its `#[PropertyOwned]`.
                                ->whereIn('subject_id', $this->scopeChild($child::query()
                                    ->withoutGlobalScopes()
                                    ->where($foreignKey, $owner->getKey()), $child)
                                    ->select('id')));
                        }
                    }));
            });
    }
}
