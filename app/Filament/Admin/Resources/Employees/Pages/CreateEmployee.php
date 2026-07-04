<?php

namespace App\Filament\Admin\Resources\Employees\Pages;

use App\Filament\Admin\Resources\Employees\EmployeeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Re-validate the target property server-side (All-Properties tamper guard).
        EmployeeResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }
}
