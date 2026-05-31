<?php

namespace App\Filament\Portal\Resources\Invoices\Pages;

use App\Filament\Portal\Resources\Invoices\InvoiceResource;
use App\Services\InvoicePdfService;
use App\Services\Paymob\PaymobPaymentInitiator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Log;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label(__('admin.actions.download_pdf'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $svc = app(InvoicePdfService::class);
                    $pdf = $svc->build($this->record);
                    return response()->streamDownload(
                        fn () => print($pdf),
                        $svc->filename($this->record),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
            Action::make('payNow')
                ->label(__('admin.actions.pay_now'))
                ->icon('heroicon-o-credit-card')
                ->color('primary')
                ->visible(fn () => config('integrations.paymob.enabled') && $this->record->balance > 0)
                ->requiresConfirmation()
                ->modalHeading(fn () => __('admin.actions.pay_now') . ' · ' . $this->record->number)
                ->action(function () {
                    try {
                        $url = app(PaymobPaymentInitiator::class)->start($this->record);

                        return redirect()->away($url);
                    } catch (\Throwable $e) {
                        Log::warning('Paymob Pay Now failed', [
                            'invoice_id' => $this->record->id,
                            'error' => $e->getMessage(),
                        ]);
                        Notification::make()
                            ->danger()
                            ->title(__('admin.notifications.pay_now_failed'))
                            ->body(__('admin.notifications.pay_now_failed_body'))
                            ->send();
                    }
                }),
        ];
    }
}
