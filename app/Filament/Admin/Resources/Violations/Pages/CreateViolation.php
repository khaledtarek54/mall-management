<?php

namespace App\Filament\Admin\Resources\Violations\Pages;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Support\Filament\PrefillsCreateForm;
use Filament\Resources\Pages\CreateRecord;

class CreateViolation extends CreateRecord
{
    use PrefillsCreateForm;

    protected static string $resource = ViolationResource::class;

    /**
     * Open with the tenant already chosen when the tenant 360's compliance tab sent us here.
     *
     * **`for_tenant`, NOT `tenant`** — `tenant` is Filament's own tenancy ROUTE parameter, and a
     * link using it puts the tenant's id in the path where the mall's slug belongs
     * (`/admin/2/violations/create`), which 404s. Same key and same reasoning as `CreatePayment`.
     *
     * The id is re-checked against the reader's OWN scoped query rather than trusted: it arrives
     * in a query string, so a hand-typed one could name a tenant in a mall this user cannot see.
     * The `EntitySelect` would refuse it at validation anyway — Filament resolves a Select's value
     * by asking for its LABEL through the scoped query — but prefilling a value the form will then
     * reject presents as the page being broken rather than as a refusal, so it is dropped here.
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $tenantId = (int) request()->query('for_tenant', 0);

        $state = ($tenantId > 0 && TenantResource::getEloquentQuery()->whereKey($tenantId)->exists())
            ? ['tenant_id' => $tenantId]
            : [];

        $this->fillFormWithDefaults($state);

        $this->callHook('afterFill');
    }

    /**
     * In "All Properties" mode the property Select is enabled and client-supplied,
     * so re-validate the submitted asset_id against the user's visible set. Also
     * stamps the recording user for the audit trail (created_by_user_id).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        ViolationResource::assertAssetInScope($data['asset_id'] ?? null);

        $data['created_by_user_id'] ??= auth()->id();

        return $data;
    }
}
