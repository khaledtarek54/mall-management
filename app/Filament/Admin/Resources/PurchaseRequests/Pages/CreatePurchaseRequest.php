<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Pages;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseRequest extends CreateRecord
{
    use GuardsAssetInScope;

    protected static string $resource = PurchaseRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->assertAssetInScope($data['asset_id'] ?? null);
        $data['requested_by_user_id'] ??= auth()->id();

        return $data;
    }
}
