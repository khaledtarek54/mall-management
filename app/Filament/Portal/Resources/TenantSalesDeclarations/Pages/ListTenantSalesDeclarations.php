<?php

namespace App\Filament\Portal\Resources\TenantSalesDeclarations\Pages;

use App\Filament\Portal\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTenantSalesDeclarations extends ListRecords
{
    protected static string $resource = TenantSalesDeclarationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('admin.actions.submit_sales')),
        ];
    }
}
