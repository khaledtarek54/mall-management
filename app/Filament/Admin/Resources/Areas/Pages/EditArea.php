<?php

namespace App\Filament\Admin\Resources\Areas\Pages;

use App\Filament\Admin\Resources\Areas\AreaResource;
use App\Models\Area;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditArea extends EditRecord
{
    protected static string $resource = AreaResource::class;

    /**
     * Filament only stamps asset_id on create, never on update — so an edit in
     * "All Properties" mode can move a zone to another property. Re-validate the
     * submitted asset_id against the user's visible set.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        AreaResource::assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        return $data;
    }

    /**
     * The supervisors relationship syncs from component state AFTER the model saves, so re-validate
     * the attached staff against the zone's property here (mirrors CreateArea). An edit can also
     * re-home the zone to another property — a supervisor valid before may now be out of scope, so
     * this must run on save, not only on create. Strips + 403s any out-of-scope attach.
     */
    protected function afterSave(): void
    {
        /** @var Area $area */
        $area = $this->record;

        AreaResource::assertSupervisorsInScope($area);
    }

    /**
     * Mirrors EditEquipment — the peer that also soft-deletes and ships a
     * TrashedFilter. Without these an area would be immortal: the model
     * soft-deletes and the table offers a "trashed" filter, but nothing could
     * ever trash, restore or purge a row — and the code would stay burned,
     * since areas_asset_code_unique counts trashed rows. (Delete stays
     * super_admin-only via RoleGatedActions::canDelete.)
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
