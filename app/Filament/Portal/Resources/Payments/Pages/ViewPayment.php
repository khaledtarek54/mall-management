<?php

namespace App\Filament\Portal\Resources\Payments\Pages;

use App\Filament\Portal\Resources\Payments\PaymentResource;
use App\Models\Payment;
use App\Services\ReceiptPdfService;
use App\Support\Filament\PdfDownloadAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Let the tenant download their receipt voucher (سند قبض). The record is already
            // tenant-scoped by the portal resource; gate on isReceived() in both visible() + action().
            PdfDownloadAction::make('downloadReceipt')
                ->label(__('admin.actions.download_receipt'))
                ->icon(Heroicon::OutlinedReceiptPercent)
                ->service(ReceiptPdfService::class)
                ->recipient(fn (Payment $record) => $record->tenant)
                ->visible(fn (Payment $record): bool => $record->isReceived())
                ->authorize(fn (Payment $record): bool => $record->isReceived()),
        ];
    }
}
