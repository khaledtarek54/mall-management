<?php

namespace App\Filament\Admin\Resources\Departments\Pages;

use App\Filament\Admin\Resources\Departments\DepartmentResource;
use Filament\Resources\Pages\EditRecord;

class EditDepartment extends EditRecord
{
    protected static string $resource = DepartmentResource::class;

    // No Delete header action — departments cannot be deleted (fixed set).

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // asset_id null = operator-wide (global) department; when a property IS set,
        // re-validate it against the user's visible set (property isolation).
        if (($data['asset_id'] ?? null) !== null) {
            DepartmentResource::assertAssetInScope($data['asset_id']);
        }

        return $data;
    }
}
