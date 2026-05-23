<?php

namespace App\Filament\Admin\Resources\UtilityMeters\Pages;

use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUtilityMeters extends ListRecords
{
    protected static string $resource = UtilityMeterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => UtilityMeterResource::canCreate()),
        ];
    }
}
