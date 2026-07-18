<?php

namespace App\Filament\Admin\Resources\Violations\Pages;

use App\Filament\Admin\Resources\Violations\ViolationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateViolation extends CreateRecord
{
    protected static string $resource = ViolationResource::class;

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
