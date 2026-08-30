<?php

namespace App\Filament\Admin\Resources\UnitOwnerships\Pages;

use App\Filament\Admin\Actions\UnitOwnershipActions;
use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use App\Support\Filament\RefreshesRecordState;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditUnitOwnership extends EditRecord
{
    use RefreshesRecordState;

    /**
     * The columns these acts rewrite underneath the form. Only fields the form actually RENDERS
     * belong here; the re-read itself still happens either way, which is what keeps a render-time
     * state closure honest.
     */
    protected function derivedStatePaths(): array
    {
        return ['status'];
    }

    protected static string $resource = UnitOwnershipResource::class;

    /**
     * Filament stamps asset_id on create only, never on update — so an edit could otherwise move an
     * ownership to another property. Re-validated against the user's visible set on every save.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        UnitOwnershipResource::assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        return $data;
    }

    /**
     * The model soft-deletes and the table ships a TrashedFilter, so without these a row would be
     * immortal — trashable by nothing and restorable by nobody. Delete stays super_admin-only via
     * RoleGatedActions, and `DeletionPolicy::WHEN_UNUSED` refuses once anything references it: an
     * ownership that has billed is corrected by transferring it, not by removing it.
     */
    protected function getHeaderActions(): array
    {
        return [
            // The record hub: what you can DO to this record lives here, not on the list.
            ...UnitOwnershipActions::all(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
