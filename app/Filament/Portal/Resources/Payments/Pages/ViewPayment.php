<?php

namespace App\Filament\Portal\Resources\Payments\Pages;

use App\Filament\Portal\Resources\Payments\PaymentResource;
use App\Models\Payment;
use App\Services\ReceiptPdfService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Let the tenant download their receipt voucher (سند قبض). The record is already
            // tenant-scoped by the portal resource; gate on isReceived() in both visible() + action().
            Action::make('downloadReceipt')
                ->label(__('admin.actions.download_receipt'))
                ->icon('heroicon-o-receipt-percent')
                ->color('gray')
                ->visible(fn (Payment $record): bool => $record->isReceived())
                ->action(function (Payment $record) {
                    abort_unless($record->isReceived(), 403);
                    $svc = app(ReceiptPdfService::class);
                    $pdf = $svc->build($record);

                    return response()->streamDownload(
                        fn () => print($pdf),
                        $svc->filename($record),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
        ];
    }
}
