<?php

namespace App\Filament\Admin\Resources\Invoices\Pages;

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Services\InvoicePdfService;
use App\Services\VoidInvoiceService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Block re-homing a draft invoice into another property via a tampered lease.
        InvoiceResource::assertLeaseAssetInScope($data['lease_id'] ?? $this->record->lease_id);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label(__('admin.actions.download_pdf'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->authorize(fn () => Auth::user()?->can('invoices.view') ?? false)
                ->action(function () {
                    $svc = app(InvoicePdfService::class);
                    $pdf = $svc->build($this->record);
                    return response()->streamDownload(
                        fn () => print($pdf),
                        $svc->filename($this->record),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
            Action::make('paymentLink')
                ->label(__('admin.actions.payment_link'))
                ->icon('heroicon-o-link')
                ->color('gray')
                ->authorize(fn () => Auth::user()?->can('invoices.view') ?? false)
                ->visible(fn () => config('integrations.paymob.enabled') && $this->record->isPayable())
                ->modalHeading(fn () => __('admin.actions.payment_link').' · '.$this->record->number)
                ->modalSubmitAction(false)
                ->modalContent(fn () => view('filament.payment-link-modal', ['invoice' => $this->record])),
            // Void (cancel) an issued invoice — the supported correction now that editing is
            // locked. Reverses any applied credit + voids the GL entry; captured cash blocks it.
            Action::make('void_invoice')
                ->label(__('admin.actions.void_invoice'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => in_array($this->record->status, ['issued', 'overdue'], true)
                    && $this->record->eta_status !== 'valid' // a filed ETA tax invoice: use a credit note
                    && round((float) $this->record->paid_amount - (float) $this->record->credit_applied_amount, 2) <= 0
                    && (Auth::user()?->can('invoices.edit') ?? false))
                ->authorize(fn () => Auth::user()?->can('invoices.edit') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.void_invoice_confirm'))
                ->schema([
                    Textarea::make('reason')
                        ->label(__('admin.fields.void_reason'))
                        ->required()
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    try {
                        app(VoidInvoiceService::class)->void($this->record, $data['reason'] ?? null);
                        $this->refreshFormData(['status', 'balance', 'notes']);
                        Notification::make()->title(__('admin.notifications.invoice_voided'))->success()->send();
                    } catch (\DomainException $e) {
                        Notification::make()
                            ->title(__('admin.notifications.invoice_void_failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
