<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Filament\Admin\Actions\TenantNoteActions;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * The tenant hub (UX-07).
 *
 * The relation managers — leases, payments, requests, notes, portal users — were already on the
 * resource, but only the EDIT page rendered them. So answering "what is going on with this tenant"
 * meant opening an edit form, and a read-only role could not get there at all.
 *
 * **ITS TABS DO NOT ADD ROWS.** Filament makes every relation manager under a `ViewRecord`
 * read-only and denies its Create/Edit/Delete before their own gates run; three affordances used
 * to escape that — the notes tab waived the rule outright, and the payments and violations tabs
 * each carried a header button linking to another resource's CREATE form, which the default cannot
 * see because it is a link rather than an action. All three are closed.
 *
 * **A row action that OPENS an existing record is deliberately not in that set**, and the
 * distinction is the whole rule rather than an oversight: the requests, sales and violations tabs
 * each carry an `open` link to that record's own page, which is navigation — it adds nothing to
 * this tenant and takes the reader to a screen with its own gates. What was reported, and what is
 * refused, is a tab that lets you ADD to it. `AViewPagesTabsDoNotWriteTest` asserts both halves.
 *
 * What survives is in the HEADER, where this panel puts acts (the list FINDS, the record ACTS):
 * `Edit` for whoever may edit the tenant, and `Log communication` for whoever may write a note.
 * The second is not decoration — `customer_service` holds `tenants.view`, `notes.view`,
 * `notes.create` and the request rights, and **no `tenants.edit`**. `ListTenants` opens for them on
 * `tenants.view`; `EditTenant` is what `tenants.edit` gates. So this is the only tenant screen
 * carrying the notes tab that they can reach, and this act is the whole of their job on it.
 */
class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Reachable to `customer_service`, who cannot open `EditTenant` at all — so it gates
            // on `notes.create` alone. See TenantNoteActions for why it is here and not in the tab.
            TenantNoteActions::logCommunication(),
            EditAction::make(),
        ];
    }
}
