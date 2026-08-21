<?php

namespace App\Filament\Admin\Resources\Holidays\Pages;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Holidays\HolidayResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHoliday extends CreateRecord
{
    use GuardsAssetInScope;

    protected static string $resource = HolidayResource::class;

    /**
     * The property picker is FREE on this form, so the submitted value is guarded here.
     *
     * Null is legitimate — it is a national holiday — so only a NAMED property is checked, and it
     * must be one the operator can see. A picker is not a gate: the value arrives in the Livewire
     * payload whatever the screen offered.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['asset_id'] ?? null) !== null) {
            $this->assertAssetInScope((int) $data['asset_id']);
        }

        return $data;
    }
}
