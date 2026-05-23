<?php

namespace App\Filament\Admin\Resources\UtilityMeters\Pages;

use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditUtilityMeter extends EditRecord
{
    protected static string $resource = UtilityMeterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
