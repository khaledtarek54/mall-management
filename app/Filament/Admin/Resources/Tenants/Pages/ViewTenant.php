<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Filament\Admin\Actions\TenantActions;
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
 * **The acts are in the HEADER, and they are the SAME three this tenant's Edit page carries** —
 * `App\Filament\Admin\Actions\TenantActions`, composed onto both. That is Yardi's shape and this
 * repo's own reading of it (`docs/benchmarks/yardi/08`): an act belongs to the RECORD and appears
 * by PERMISSION, never by which page you opened. So a `manager` standing on the read-only page can
 * still record a receipt, and `customer_service` — `tenants.view`, `notes.view`, `notes.create`,
 * the request rights and **no `tenants.edit`**, so `ListTenants` opens for them and `EditTenant`
 * does not — can log the call it just took, which is the whole of its job here.
 */
class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The record hub's acts, identical to the ones on `EditTenant` and gated only by what
            // the operator holds — see TenantActions for why an act belongs to the record rather
            // than to a page or a tab. `customer_service` cannot open `EditTenant` at all, so this
            // is where its one function lives; `manager` sees the same three here as there.
            ...TenantActions::all(),
            EditAction::make(),
        ];
    }
}
