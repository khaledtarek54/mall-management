<?php

namespace App\Filament\Admin\Resources\Violations\Pages;

use App\Filament\Admin\Resources\Violations\ViolationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListViolations extends ListRecords
{
    protected static string $resource = ViolationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
