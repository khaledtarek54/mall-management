<?php

namespace App\Filament\Admin\Resources\TenantRequestSubcategories\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\TenantRequestSubcategories\TenantRequestSubcategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTenantRequestSubcategories extends ListRecords
{
    protected static string $resource = TenantRequestSubcategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
