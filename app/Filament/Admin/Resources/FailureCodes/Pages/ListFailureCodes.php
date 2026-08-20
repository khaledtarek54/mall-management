<?php

namespace App\Filament\Admin\Resources\FailureCodes\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\FailureCodes\FailureCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFailureCodes extends ListRecords
{
    protected static string $resource = FailureCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
