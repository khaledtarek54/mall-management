<?php

namespace App\Filament\Admin\Resources\Holidays\Pages;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Holidays\HolidayResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHoliday extends EditRecord
{
    use GuardsAssetInScope;

    protected static string $resource = HolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /** Filament stamps `asset_id` on create but never on update, so the edit path guards too. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['asset_id'] ?? null) !== null) {
            $this->assertAssetInScope((int) $data['asset_id']);
        }

        return $data;
    }
}
