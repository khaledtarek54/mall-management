<?php

namespace App\Filament\Admin\Resources\TenantSalesDeclarations\Pages;

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Models\User;
use App\Services\PercentageRentCalculationService;
use App\Support\MorphMap;
use Filament\Resources\Pages\CreateRecord;

class CreateTenantSalesDeclaration extends CreateRecord
{
    protected static string $resource = TenantSalesDeclarationResource::class;

    /**
     * Open with the lease already chosen when the lease's own tab sent us here.
     *
     * The tab lists what a tenant has declared and could not add to it, so recording this month
     * meant leaving the lease, opening the register and finding the same lease again in a picker —
     * the loop this codebase removed from the collections worklist and from the lease's invoices.
     *
     * **The id is re-checked against the reader's own scoped query, not trusted.** It arrives in a
     * query string, so a hand-typed one could name a lease in a mall this user cannot see. The
     * EntitySelect would refuse it at validation anyway — Filament resolves a Select's value by
     * asking for its LABEL through the scoped query — but prefilling a value the form will later
     * reject presents as the page being broken rather than as a refusal.
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $leaseId = (int) request()->query('lease', 0);

        $this->form->fill(
            $leaseId > 0 && LeaseResource::getEloquentQuery()->whereKey($leaseId)->exists()
                ? ['lease_id' => $leaseId]
                : null,
        );

        $this->callHook('afterFill');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The declaration's property comes from its lease — re-validate the
        // submitted lease is within the user's visible set (property isolation).
        TenantSalesDeclarationResource::assertLeaseAssetInScope($data['lease_id'] ?? null);

        $data['declared_at'] ??= now();
        $data['declared_by_type'] ??= MorphMap::alias(User::class);
        $data['declared_by_id'] ??= auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        app(PercentageRentCalculationService::class)->recalculate($this->record);
    }
}
