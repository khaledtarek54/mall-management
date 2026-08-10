<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * The tenant hub (UX-07).
 *
 * The relation managers — leases, payments, requests, notes, portal users — were already on the
 * resource, but only the EDIT page rendered them. So answering "what is going on with this tenant"
 * meant opening an edit form, and a read-only role could not get there at all.
 */
class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
