<?php

namespace App\Support;

use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Who may export a table — the counterpart to {@see Imports}, and the floor those buttons never had.
 *
 * ## Export is deliberately the WIDE door
 *
 * The FRD singles import out and lets everything else through: *"The system shall restrict data
 * import/upload functionality to Admin users only; all other roles may export/download but not
 * import."* So this is **not** a permission of its own. Whoever may read the list on screen may take
 * it away — the same rule {@see ExportsReport} states for the
 * nineteen report pages, and the reason the gate here is the resource's own `canViewAny()` rather
 * than a new `exports.execute` key that every role would then have to be granted.
 *
 * ## What was actually wrong, and it was not authorization
 *
 * Thirteen export actions across seven tables carried no gate at all. That reads as a data-egress
 * hole and is not one: Filament exports `getTableQueryForExport()`, i.e. the resource's own scoped
 * query with the operator's filters applied, so an export can never return a row the list would not.
 *
 * The bug is that **six of the seven Tables classes are shared with the PORTAL** — one
 * `InvoicesTable::configure()` serves `Filament\Admin\Resources\Invoices` and
 * `Filament\Portal\Resources\Invoices` alike — so a tenant saw an Export button on their invoices,
 * payments, leases and credit notes. Clicking it cannot work: Filament resolves the exporting user
 * from `Filament::getAuthGuard()`, which on the portal is `portal` and yields a `TenantUser`, and
 * then writes its id into `exports.user_id` — a foreign key to `users`
 * (`2026_05_14_122937_create_exports_table`). The click either violates the constraint or, where an
 * admin happens to hold that id, files a tenant's export under a stranger's name.
 *
 * ## Why the floor is `instanceof User` and not `?->can()`
 *
 * `TenantUser` does not use spatie's `HasRoles`. `?->can()` answers false there today — for the
 * wrong reason — and would answer TRUE the day the portal grows a policy, silently re-opening this.
 * The same reasoning put `instanceof User` in the Filament CRUD gates (2026-08-22).
 *
 * A portal export is not refused here so much as **not offered**: if tenants should be able to take
 * their own ledger away, that is a portal feature with its own exporter and its own `exports` FK,
 * not an admin button leaking onto their screen.
 */
class Exports
{
    /**
     * May the current user export this resource's table?
     *
     * @param  class-string  $resource  the ADMIN resource whose `canViewAny()` decides — passed
     *                                  rather than a permission string so the gate cannot drift
     *                                  from the list page it sits on
     * @param  string|null  $alsoRequires  an extra permission for a table whose export is wider
     *                                     than its list (tenant requests: a technician sees only
     *                                     what is assigned to them, so exporting the lot needs
     *                                     `requests.view_all`)
     */
    public static function allowed(string $resource, ?string $alsoRequires = null): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        if (! $resource::canViewAny()) {
            return false;
        }

        return $alsoRequires === null || $user->can($alsoRequires);
    }
}
