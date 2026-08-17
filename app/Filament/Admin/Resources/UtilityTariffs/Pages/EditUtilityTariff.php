<?php

namespace App\Filament\Admin\Resources\UtilityTariffs\Pages;

use App\Filament\Admin\Resources\UtilityTariffs\UtilityTariffResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUtilityTariff extends EditRecord
{
    protected static string $resource = UtilityTariffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // `DeletableWhenUnused`: refused once any meter is priced by this tariff, because the
            // ladder is what explains historical recharges. Deactivate instead — it leaves the meter
            // picker immediately and keeps explaining the past.
            DeleteAction::make(),
        ];
    }
}
