<?php

namespace App\Filament\Admin\Resources\Equipment\Pages;

use App\Filament\Admin\Resources\Equipment\EquipmentResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditEquipment extends EditRecord
{
    protected static string $resource = EquipmentResource::class;

    /**
     * Filament only stamps asset_id on create, never on update — so an edit in
     * "All Properties" mode can move a machine to another property. Re-validate the
     * submitted asset_id against the user's visible set.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        EquipmentResource::assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        return $data;
    }

    /**
     * Mirrors EditUnit — the peer that also soft-deletes and ships a TrashedFilter.
     * Without these a machine would be immortal: the model soft-deletes and the table
     * offers a "trashed" filter, but nothing could ever trash, restore or purge a row.
     * That also strands the code, since equipment_asset_code_unique counts trashed rows —
     * so a typo'd `CH-O1` would burn that code forever with no way to reclaim it.
     * (Delete stays super_admin-only via RoleGatedActions::canDelete.)
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
