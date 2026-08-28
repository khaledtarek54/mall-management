<?php

namespace App\Filament\Admin\Resources\FixedAssets\Pages;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Services\DepreciationService;
use App\Support\Filament\AnnouncesLedgerRestatement;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditFixedAsset extends EditRecord
{
    use AnnouncesLedgerRestatement;

    protected static string $resource = FixedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // **The ledger panel, on the screen where the edit happens.** The factory has existed
            // since CHANGE-IMPACT-PLAN §6.1 and was mounted on five LIST tables only — which is
            // where you audit, not where you act. An operator about to retype a figure could not
            // see what the document had already done to the books without leaving the page.
            LedgerEntryAction::make(),
            DeleteAction::make()->visible(fn () => FixedAssetResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Terminal-record immutability: a disposed asset is written off — editing it
        // (e.g. its acquisition_cost) would re-derive the acquisition entry but strand
        // the disposal's offsetting Furniture credit. Block it (defence for a direct URL).
        abort_unless($this->getRecord()->status === 'active', 403);

        // A re-cost must not drop the depreciable base below what has already been
        // depreciated, or NBV goes negative and depreciation stops forever (F-86). The form
        // field carries the inline message (see FixedAssetForm); this is the server-side
        // backstop for a crafted request, thrown as validation so it renders on the field
        // rather than 500-ing. Coerced like the columns: a blank field is 0, not null.
        try {
            app(DepreciationService::class)->assertRecostValid(
                $this->getRecord(),
                (float) ($data['acquisition_cost'] ?? $this->getRecord()->acquisition_cost),
                (float) ($data['salvage_value'] ?? $this->getRecord()->salvage_value),
            );
        } catch (\DomainException $e) {
            throw ValidationException::withMessages([
                'data.acquisition_cost' => $e->getMessage(),
            ]);
        }

        // Re-validate the target property server-side (can't re-home into another mall).
        FixedAssetResource::assertAssetInScope($data['asset_id'] ?? $this->getRecord()->asset_id);

        return $data;
    }
}
