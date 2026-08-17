<?php

namespace App\Filament\Admin\Resources\TenantSalesDeclarations\Pages;

use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Models\User;
use App\Services\PercentageRentCalculationService;
use App\Support\MorphMap;
use Filament\Resources\Pages\CreateRecord;

class CreateTenantSalesDeclaration extends CreateRecord
{
    protected static string $resource = TenantSalesDeclarationResource::class;

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
