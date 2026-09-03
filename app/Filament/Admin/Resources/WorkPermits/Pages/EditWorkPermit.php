<?php

namespace App\Filament\Admin\Resources\WorkPermits\Pages;

use App\Filament\Admin\Actions\WorkPermitActions;
use App\Filament\Admin\Resources\WorkPermits\WorkPermitResource;
use App\Models\WorkPermit;
use App\Support\Filament\RefreshesRecordState;
use Filament\Resources\Pages\EditRecord;

class EditWorkPermit extends EditRecord
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

    protected static string $resource = WorkPermitResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // ── A LIVE PERMIT'S FORM SAVES NOTHING ────────────────────────────────────────────────
        //
        // `WorkPermitForm` disables every field once the permit leaves draft, which is the UI truth
        // beside `WorkPermit::updating`'s gate — but **a disabled Filament field is still
        // DEHYDRATED** (measured on v4.11.8: all thirteen came back `disabled=true dehydrated=true`),
        // so the state still reaches the record. That mattered because `DateTimePicker->seconds(false)`
        // truncates the window to the minute: filling from an untouched form made `valid_from` and
        // `valid_to` dirty against a stored value carrying seconds, and pressing **Save without
        // touching anything** was refused by the model. `DemoSeeder` builds every permit from
        // `Carbon::now()`, so that is the ordinary state of a real row.
        //
        // Dropping the payload is deliberate rather than clever: it does not depend on Filament's
        // dehydration semantics, and it says in one line what the screen means. The model guard is
        // still the gate — this is only what stops the page manufacturing a write nobody asked for.
        if ($this->getRecord()->status !== WorkPermit::STATUS_DRAFT) {
            return [];
        }

        // Filament stamps asset_id on create only, never on update — so the edit path needs its
        // own guard or a crafted payload could move a permit to another mall.
        WorkPermitResource::assertAssetInScope((int) ($data['asset_id'] ?? 0));

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            // The record hub: what you can DO to this record lives here, not on the list.
            ...WorkPermitActions::all(),
        ];
    }
}
