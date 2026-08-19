<?php

namespace App\Filament\Admin\Resources\WorkPermits\Pages;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\WorkPermits\WorkPermitResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkPermit extends CreateRecord
{
    // The form exposes an editable asset_id, so Filament's tenant auto-stamp must be off or it
    // clobbers the operator's chosen mall — the "Announcements tenancy trap".
    use BypassesFilamentTenantAutoScope;

    protected static string $resource = WorkPermitResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The property pin is a UI truth, not a gate — the value still arrives in the Livewire
        // payload, so the write guard runs regardless.
        WorkPermitResource::assertAssetInScope((int) ($data['asset_id'] ?? 0));

        return $data;
    }
}
