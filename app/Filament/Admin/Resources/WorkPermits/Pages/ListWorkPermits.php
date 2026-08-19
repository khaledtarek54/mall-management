<?php

namespace App\Filament\Admin\Resources\WorkPermits\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\WorkPermits\WorkPermitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkPermits extends ListRecords
{
    protected static string $resource = WorkPermitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
