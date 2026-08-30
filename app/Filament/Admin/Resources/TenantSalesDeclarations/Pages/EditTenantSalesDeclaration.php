<?php

namespace App\Filament\Admin\Resources\TenantSalesDeclarations\Pages;

use App\Filament\Admin\Actions\SalesDeclarationActions;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Services\PercentageRentCalculationService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditTenantSalesDeclaration extends EditRecord
{
    protected static string $resource = TenantSalesDeclarationResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Block re-homing via a tampered lease (property is derived from the lease).
        TenantSalesDeclarationResource::assertLeaseAssetInScope($data['lease_id'] ?? $this->record->lease_id);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            // The acts themselves, not just Delete. They lived only on the LIST, so an operator who
            // opened a declaration to check its figures had to go back to the list to lock it —
            // and locking is the decision the figures are being checked FOR.
            //
            // The same definitions the table renders (`SalesDeclarationActions`), never a second
            // copy: `lock()` raises an invoice and `voidLocked()` reverses one, and two definitions
            // of either is two answers to the same question.
            ...SalesDeclarationActions::all(),
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
