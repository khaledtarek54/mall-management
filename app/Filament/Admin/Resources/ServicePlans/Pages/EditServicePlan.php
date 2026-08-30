<?php

namespace App\Filament\Admin\Resources\ServicePlans\Pages;

use App\Filament\Admin\Actions\ServicePlanActions;
use App\Filament\Admin\Resources\ServicePlans\ServicePlanResource;
use App\Support\Filament\RefreshesRecordState;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServicePlan extends EditRecord
{
    use RefreshesRecordState;

    /**
     * The columns these acts rewrite underneath the form. Only fields the form actually RENDERS
     * belong here; the re-read itself still happens either way, which is what keeps a render-time
     * state closure honest.
     */
    protected function derivedStatePaths(): array
    {
        return ['next_due_date'];
    }

    protected static string $resource = ServicePlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The record hub: what you can DO to this record lives here, not on the list.
            ...ServicePlanActions::all(),
            DeleteAction::make()->visible(fn () => ServicePlanResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        ServicePlanResource::assertAssetInScope($data['asset_id'] ?? $this->getRecord()->asset_id);

        return $data;
    }
}
