<?php

namespace App\Filament\Admin\Resources\TenantSalesDeclarations\Pages;

use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Services\PercentageRentCalculationService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditTenantSalesDeclaration extends EditRecord
{
    protected static string $resource = TenantSalesDeclarationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        if ($this->record->status !== 'locked') {
            app(PercentageRentCalculationService::class)->recalculate($this->record);
        }
    }
}
