<?php

namespace App\Filament\Portal\Resources\Invoices\Pages;

use App\Actions\Api\V1\Payments\RecordDemoPaymentAction;
use App\Filament\Portal\Resources\Invoices\InvoiceResource;
use App\Services\InvoicePdfService;
use App\Services\Paymob\PaymobPaymentInitiator;
use App\Support\Portal;
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
                        fn () => print ($pdf),
                        $svc->filename($this->record),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
            Action::make('payNow')
                ->label(__('admin.actions.pay_now'))
                ->icon('heroicon-o-credit-card')
                ->color('primary')
                ->visible(fn () => $this->canPayNow())
                ->requiresConfirmation()
                ->modalHeading(fn () => __('admin.actions.pay_now').' · '.$this->record->number)
                ->action(function () {
                    // visible() styles the page; it does NOT gate dispatch — Filament's
                    // mountAction() checks isDisabled(), never isVisible(). Without this a
                    // read-only TenantUser could start a real card payment.
                    abort_unless($this->canPayNow(), 403);

                    try {
                        $session = app(PaymobPaymentInitiator::class)->start($this->record, \App\Models\Payment::CHANNEL_PORTAL);

                        return redirect()->away($session['iframe_url']);
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
            // Demo payment — shown only while Paymob is disabled. Runs the real
            // capture path so the invoice flips to paid, a payment is created,
            // and the tenant is notified — then refreshes the page so the
            // operations team sees the "Paid" state immediately.
            Action::make('payDemo')
                ->label(__('admin.actions.pay_now'))
                ->icon('heroicon-o-credit-card')
                ->color('primary')
                ->visible(fn () => $this->canPayDemo())
                ->requiresConfirmation()
                ->modalHeading(fn () => __('admin.actions.pay_now').' · '.$this->record->number)
                ->modalDescription(fn () => __('admin.actions.pay_demo_modal_body', [
                    'amount' => number_format((float) $this->record->balance, 2),
                ]))
                ->modalSubmitActionLabel(__('admin.actions.pay_now'))
                ->action(function () {
                    // See canPayNow() — visible() is not a dispatch gate.
                    abort_unless($this->canPayDemo(), 403);

                    app(RecordDemoPaymentAction::class)->handle($this->record);

                    $this->record->refresh();

                    Notification::make()
                        ->success()
                        ->title(__('admin.notifications.payment_received_title'))
                        ->body(__('admin.actions.pay_demo_success', ['number' => $this->record->number]))
                        ->send();
                }),
        ];
    }

    /**
     * Only an admin TenantUser may pay, and only a live gateway can take the money.
     * Stated once so the button's visibility and its authorization guard cannot drift —
     * they are the same question, and `visible()` alone never answers it (see the
     * abort_unless calls above).
     */
    private function canPayNow(): bool
    {
        return Portal::isAdmin()
            && config('integrations.paymob.enabled')
            && (float) $this->record->balance > 0;
    }

    /** The demo counterpart — shown only while Paymob is disabled. */
    private function canPayDemo(): bool
    {
        return Portal::isAdmin()
            && ! config('integrations.paymob.enabled')
            && (float) $this->record->balance > 0
            && ! in_array($this->record->status, ['cancelled', 'credited', 'written_off'], true);
    }
}
