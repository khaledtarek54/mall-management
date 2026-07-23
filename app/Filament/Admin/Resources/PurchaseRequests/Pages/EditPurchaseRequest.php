<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Pages;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use App\Services\PurchaseOrderPdfService;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseRequest extends EditRecord
{
    use GuardsAssetInScope;

    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The PO document, once the request has become an order — parity with the table.
            Action::make('downloadPo')
                ->label(__('admin.procurement.actions.download_po'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => in_array($this->record->status, [PurchaseRequest::STATUS_ORDERED, PurchaseRequest::STATUS_RECEIVED], true))
                ->action(function () {
                    $svc = app(PurchaseOrderPdfService::class);
                    $pdf = $svc->build($this->record);

                    return response()->streamDownload(
                        fn () => print($pdf),
                        $svc->filename($this->record),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
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
