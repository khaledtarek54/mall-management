<?php

namespace App\Filament\Admin\Resources\Units\Pages;

use App\Filament\Admin\Actions\UnitActions;
use App\Filament\Admin\Resources\Concerns\FillsCustomFields;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Support\Filament\RefreshesRecordState;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditUnit extends EditRecord
{
    use FillsCustomFields;
    use RefreshesRecordState;

    /**
     * `area_sqm` is what re-measuring rewrites, and it is rendered a few centimetres below the
     * button that changes it. `RemeasureUnitService` re-reads the unit into a new instance, so
     * without the re-read the operator records a re-survey, is told it worked, and goes on reading
     * the old area.
     *
     * `net_area_sqm` rides with the survey since point 20 (2026-09-12) and MUST be refilled too —
     * and this one is worse than stale, because the field is EDITABLE: found by the review, a
     * survey that shrank the gross to 80 and re-stated the net at 70 left the form reading 85, so
     * the next Save was refused on a field nobody touched; a survey that GREW the gross and moved
     * the net up left the old net on the form, and a plain Save wrote it back under a success
     * toast — the survey's net silently reverted.
     */
    protected function derivedStatePaths(): array
    {
        return ['area_sqm', 'net_area_sqm'];
    }

    protected static string $resource = UnitResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Block re-homing into a property outside the user's visible set — asset_id is
        // editable in All-Properties mode and is NOT re-stamped by Filament on update.
        $assetId = $data['asset_id'] ?? $this->record->asset_id;
        UnitResource::assertAssetInScope($assetId);

        // The facility zone must belong to this same property (checked against the FINAL
        // asset_id, so it also catches an edit that re-homes the unit).
        UnitResource::assertAreaInScope($data['area_id'] ?? null, $assetId);

        // Same rule, same reason, on the floor relation — and checked against the FINAL asset_id
        // so it also catches an edit that re-homes the unit and leaves the old floor behind.
        UnitResource::assertFloorInScope($data['floor_id'] ?? null, $assetId);

        return $data;
    }

    protected function afterSave(): void
    {
        // Re-project status from the unit's leases — an operator-set 'occupied'/'reserved' with no
        // backing lease self-heals to 'vacant' (and 'maintenance' is preserved). See CreateUnit.
        $this->record->recomputeStatus();
    }

    protected function getHeaderActions(): array
    {
        return [
            // The record hub: what you can DO to this unit lives here, not on the list.
            ...UnitActions::all(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
