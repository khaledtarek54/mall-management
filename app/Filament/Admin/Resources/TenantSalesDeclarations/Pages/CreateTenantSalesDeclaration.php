<?php

namespace App\Filament\Admin\Resources\TenantSalesDeclarations\Pages;

use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Services\PercentageRentCalculationService;
use Filament\Resources\Pages\CreateRecord;

class CreateTenantSalesDeclaration extends CreateRecord
{
    protected static string $resource = TenantSalesDeclarationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['declared_at'] ??= now();
        $data['declared_by_type'] ??= \App\Models\User::class;
        $data['declared_by_id'] ??= auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        app(PercentageRentCalculationService::class)->recalculate($this->record);
    }
}
