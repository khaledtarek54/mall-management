<?php

namespace App\Filament\Admin\Resources\Areas\Pages;

use App\Filament\Admin\Resources\Areas\AreaResource;
use App\Models\Area;
use Filament\Resources\Pages\CreateRecord;

class CreateArea extends CreateRecord
{
    protected static string $resource = AreaResource::class;

    /**
     * Transactional, because the supervisor guard runs AFTER the row saves (`afterCreate()` — a
     * relationship field syncs from component state once the model exists) and refuses with a 403.
     * Filament's `CreateRecord` defaults this to the PANEL's setting and no panel opts in, so
     * without it that 403 left an EMPTY ZONE on disk: measured by review on a scalar `supervisors`
     * payload, which slips the array validation (2026-09-10). The tab on the property page had
     * just been given `->databaseTransaction()` for the same guard under a comment saying this
     * page "always is" — it was not. Same idiom as `CreateLease`.
     */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * In "All Properties" mode the property Select is enabled and client-supplied,
     * so re-validate the submitted asset_id against the user's visible set.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        AreaResource::assertAssetInScope($data['asset_id'] ?? null);

        return $data;
    }

    /**
     * Supervisors is a relationship field — it syncs from component state AFTER the model saves, so
     * re-validate the attached staff against the zone's property here (the mutate hooks can't see
     * it). Strips + 403s any out-of-scope attach.
     */
    protected function afterCreate(): void
    {
        /** @var Area $area */
        $area = $this->record;

        AreaResource::assertSupervisorsInScope($area);
    }
}
