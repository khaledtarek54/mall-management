<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Pages;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseRequest extends EditRecord
{
    use GuardsAssetInScope;

    protected static string $resource = PurchaseRequestResource::class;

    // Filament only stamps asset_id on create, never on update — so an edit that derives or
    // exposes it must re-check, or the tenancy scope is bypassed on every save.
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        return $data;
    }
}
