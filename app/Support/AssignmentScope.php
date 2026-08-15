<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Show me only the work that is mine" (FR-USR-04).
 *
 * The FRD, verbatim: *"Every user shall see only the requests/work orders assigned to them,
 * **filtered by role and assignment**."* — and its role table qualifies who that means:
 *
 *   Admin (per mall)     full access for their assigned mall
 *   Coordinator          manages assignment and oversight of requests/work orders
 *   In-house Technician  normal employee; **sees only work assigned to them**
 *
 * So "every user" is not literal: **the role decides whether the assignment filter applies at
 * all.** A coordinator who could only see their own work could not assign anything; an admin who
 * could only see their own could not run a mall. The technician is the case the requirement exists
 * for.
 *
 * ---
 *
 * **This is the system's second scoping primitive, and it composes with the first.** `TenantScope`
 * answers "which properties may you see"; this answers "which rows within them are yours". They
 * are independent and both apply: a technician assigned to a job in a mall they are not assigned to
 * sees nothing, because property scoping already removed it.
 *
 * **It is a query constraint, never a filter.** A Filament filter is a convenience the user can
 * clear; a `->where()` in `getEloquentQuery()` is not. That distinction is the whole requirement —
 * "sees only work assigned to them" is not satisfied by a checkbox they can untick. It also covers
 * the record page for free, because Filament resolves a record through the same query: a technician
 * who guesses another job's URL gets a 404, not somebody else's work order.
 *
 * **Expressed as a permission, not a role list.** `RoleGatedActions` gates on
 * `{module}.{action}` permissions throughout, and a hard-coded `in_array($role, ['technician'])`
 * would need a deploy every time the operator invents a role. Holding `{module}.view_all` means
 * "you oversee this module"; lacking it means you see your own work. Every existing role was
 * granted it, so this is additive: nothing that worked yesterday narrows today.
 */
class AssignmentScope
{
    /**
     * Does this user see everything in the module, or only what is theirs?
     *
     * Fails OPEN to *restricted* — the safe direction. A user with no permissions at all, or an
     * unauthenticated one, sees their own work (which is nothing), rather than everyone's.
     */
    public static function isRestricted(?User $user, string $module): bool
    {
        if ($user === null) {
            return true;
        }

        return ! $user->can("{$module}.view_all");
    }

    /**
     * Constrain a query to the rows assigned to the current user, if their role says so.
     *
     * @param  Builder  $query  the resource's query
     * @param  string  $module  the permission module (`maintenance`, `facility`)
     * @param  string  $column  the assignee column — it differs per table (`assigned_to` on
     *                          tenant_requests, `assigned_to_user_id` on facility_work_orders),
     *                          which is exactly why this is one primitive and not two copies
     */
    public static function apply(Builder $query, string $module, string $column, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! self::isRestricted($user, $module)) {
            return $query;
        }

        // Unassigned rows are deliberately INVISIBLE to a restricted user, not visible.
        // "Sees only work assigned to them" means a job nobody has assigned yet is not theirs —
        // it is the coordinator's to hand out. `where(col, id)` excludes NULL in SQL anyway; this
        // says so on purpose rather than by accident.
        return $query->where($column, $user?->id ?? 0);
    }
}
