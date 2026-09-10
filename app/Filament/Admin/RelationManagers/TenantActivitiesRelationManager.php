<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\ShowsItsChildrensActivity;
use App\Models\Lease;
use App\Models\TenantDocument;
use App\Models\TenantUser;

/**
 * A tenant's Activity Log, widened to the things that MAKE UP the relationship.
 *
 * Reported by the tester: *"Tenant Activity Log only records changes made in Tenant
 * Information/settings — changes made in Portal & App Logins, Leases, and the other tabs under a
 * tenant are not logged."* The stock relation manager reads `activitiesAsSubject` — rows whose
 * subject is the tenant ROW — so everything created under it filed elsewhere and the tab never
 * asked. TWO causes behind one symptom, and the first is the serious one:
 *
 *  - **A portal login was audited NOWHERE in the system.** A `TenantUser` is a credential: since
 *    the 2026-09-05 unification one row opens both the tenant portal and the mobile API, and
 *    `is_admin` decides whether that person may WRITE. Creating one, promoting somebody to admin or
 *    moving their email left no trace at all — an unrecorded grant of access to a company's own
 *    billing, which is the one class of change an audit trail exists for. `TenantUser` is audited
 *    now (`password` excluded by `ActivityLogging::CREDENTIALS`, so the trail says THAT it changed
 *    and never what to).
 *  - **The tab only read the tenant's own row.** Leases and documents were already audited and
 *    simply filed under their own subject.
 *
 * **WHAT IS DELIBERATELY ABSENT is the more important half.** Invoices, payments, credit notes,
 * deposits, violations, requests and work orders all carry a `tenant_id`, and every one of them is a
 * REGISTER with its own screen, its own audit and its own volume. Putting them here would bury the
 * three facts an operator opens this tab to check under a year of billing — the same reasoning that
 * keeps a property's tab to its spatial make-up rather than to everything `#[PropertyOwned]`.
 *
 * What is left is the RELATIONSHIP: who may sign in, what they have signed, and what they hold.
 */
class TenantActivitiesRelationManager extends ActivitiesRelationManager
{
    use ShowsItsChildrensActivity;

    protected function activityChildren(): array
    {
        return [
            TenantUser::class => 'tenant_id',
            Lease::class => 'tenant_id',
            TenantDocument::class => 'tenant_id',
        ];
    }
}
