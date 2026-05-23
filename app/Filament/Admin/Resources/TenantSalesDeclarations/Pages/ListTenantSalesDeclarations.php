<?php

namespace App\Filament\Admin\Resources\TenantSalesDeclarations\Pages;

use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTenantSalesDeclarations extends ListRecords
{
    protected static string $resource = TenantSalesDeclarationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => TenantSalesDeclarationResource::canCreate()),
        ];
    }
}
