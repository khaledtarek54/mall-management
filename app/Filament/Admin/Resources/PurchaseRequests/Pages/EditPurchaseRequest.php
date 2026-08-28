<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Pages;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use App\Services\PurchaseOrderPdfService;
use App\Support\Filament\PdfDownloadAction;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseRequest extends EditRecord
{
    use GuardsAssetInScope;

    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The PO document, once the request has become an order — parity with the table.
            PdfDownloadAction::make('downloadPo')
                ->label(__('admin.procurement.actions.download_po'))
                ->service(PurchaseOrderPdfService::class)
                // A PO leaves the building toward a counterparty who never sees the panel: a local
                // contractor and an international lift maintainer want opposite languages, from
                // this same button, on the same afternoon.
                ->recipient(fn (PurchaseRequest $record) => $record->vendor)
                ->visible(fn () => in_array($this->record->status, [PurchaseRequest::STATUS_ORDERED, PurchaseRequest::STATUS_RECEIVED], true)),
        ];
    }

    // Filament only stamps asset_id on create, never on update — so an edit that derives or
    // exposes it must re-check, or the tenancy scope is bypassed on every save.
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        return $data;
    }
}
