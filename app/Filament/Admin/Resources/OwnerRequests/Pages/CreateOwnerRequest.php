<?php

namespace App\Filament\Admin\Resources\OwnerRequests\Pages;

use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Services\OwnerRequestService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateOwnerRequest extends CreateRecord
{
    protected static string $resource = OwnerRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // asset_id is optional (a general owner request targets no single property);
        // when a property IS chosen, re-validate it against the user's visible set so
        // a tampered value can't raise a request against a property they don't own.
        if (($data['asset_id'] ?? null) !== null) {
            OwnerRequestResource::assertAssetInScope($data['asset_id']);
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(OwnerRequestService::class)->create($data, Auth::user());
    }
}
