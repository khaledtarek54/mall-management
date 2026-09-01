<?php

namespace App\Filament\Portal\Resources\Invoices\Pages;

use App\Actions\Api\V1\Payments\RecordDemoPaymentAction;
use App\Filament\Portal\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoicePdfService;
use App\Services\Paymob\PaymobPaymentInitiator;
use App\Support\DemoPayments;
use App\Support\Filament\PdfDownloadAction;
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
            PdfDownloadAction::make('downloadPdf')
                ->label(__('admin.actions.download_pdf'))
                ->service(InvoicePdfService::class)
                ->recipient(fn (Invoice $record) => $record->tenant),
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
                        $session = app(PaymobPaymentInitiator::class)->start($this->record, Payment::CHANNEL_PORTAL);

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
     *
     * **WHICH invoices may take money is `Invoice::isPayable()`, not a test written here.** These
     * two methods sat three lines apart and disagreed: the demo one carried a denylist of three
     * statuses, and this one — the one that opens a LIVE Paymob checkout — checked no status at
     * all, so a tenant was offered real card payment on an invoice the operator had written off to
     * bad debt while the fake button beside it correctly refused. Neither list knew about `draft`.
     * `isPayable()` now asks `App\Support\InvoiceSettlement`, the one register for the question,
     * and nets prior write-offs out of the amount.
     */
    private function canPayNow(): bool
    {
        // `isPayable()` nets prior write-offs, so it is an aggregate unless the relation is loaded.
        // This page asks it three to four times per render (both predicates plus each action's own
        // `abort_unless`), and Filament does not eager-load for a record page.
        $this->record->loadMissing('writeOffs');

        return Portal::isAdmin()
            && config('integrations.paymob.enabled')
            && $this->record->isPayable();
    }

    /** The demo counterpart — availability is DemoPayments' decision, not this screen's. */
    private function canPayDemo(): bool
    {
        return Portal::isAdmin()
            && DemoPayments::enabled()
            && $this->record->isPayable();
    }
}
