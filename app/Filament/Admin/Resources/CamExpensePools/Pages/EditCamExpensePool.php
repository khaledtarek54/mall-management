<?php

namespace App\Filament\Admin\Resources\CamExpensePools\Pages;

use App\Filament\Admin\Actions\CamExpensePoolActions;
use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Support\Filament\RefreshesRecordState;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCamExpensePool extends EditRecord
{
    use RefreshesRecordState;

    /**
     * The columns these acts rewrite underneath the form. Only fields the form actually RENDERS
     * belong here; the re-read itself happens either way, which is what keeps a render-time state
     * closure honest.
     */
    protected function derivedStatePaths(): array
    {
        return ['status'];
    }

    protected static string $resource = CamExpensePoolResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Block re-homing into a property outside the user's visible set — asset_id is
        // editable in All-Properties mode and is NOT re-stamped by Filament on update.
        CamExpensePoolResource::assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (\DomainException $e) {
            // Backstop for the recovery-basis freeze guard (the basis fields are also disabled once an
            // allocation is billed): surface the guard's "void the billed allocations first" message as
            // a clean danger toast instead of a raw Livewire 500, and halt the save.
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
            $this->halt();

            return $record; // unreachable — halt() throws Halt — but satisfies the : Model return type
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // The record hub: what you can DO to this record lives here, not on the list.
            ...CamExpensePoolActions::all(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
