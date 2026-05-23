<?php

namespace App\Filament\Portal\Resources\TenantSalesDeclarations\Pages;

use App\Filament\Portal\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Models\Tenant;
use App\Services\PercentageRentCalculationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateTenantSalesDeclaration extends CreateRecord
{
    protected static string $resource = TenantSalesDeclarationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['declared_at'] ??= now();
        $data['declared_by_type'] = Tenant::class;
        $data['declared_by_id'] = Auth::guard('portal')->id();
        $data['status'] = 'submitted';

        return $data;
    }

    protected function afterCreate(): void
    {
        app(PercentageRentCalculationService::class)->recalculate($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
