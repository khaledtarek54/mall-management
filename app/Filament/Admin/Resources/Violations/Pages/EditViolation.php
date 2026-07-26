<?php

namespace App\Filament\Admin\Resources\Violations\Pages;

use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Models\Violation;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditViolation extends EditRecord
{
    protected static string $resource = ViolationResource::class;

    /**
     * Filament only stamps asset_id on create, never on update — so an edit in
     * "All Properties" mode can move a violation to another property. Re-validate
     * the submitted asset_id against the user's visible set.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        ViolationResource::assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        return $data;
    }

    /**
     * The model soft-deletes and the table ships a TrashedFilter, so the Edit page
     * must expose Delete / ForceDelete / Restore (Delete stays super_admin-only via
     * RoleGatedActions::canDelete). Mirrors EditArea / EditEquipment.
     *
     * ForceDelete is hidden for a billed violation — hard-removing it would sever the audit link to
     * a live fine invoice (the model's forceDeleting guard is the real backstop; this is UX only).
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make()
                ->visible(fn (Violation $record) => ! $record->isBilled()),
            RestoreAction::make(),
        ];
    }
}
