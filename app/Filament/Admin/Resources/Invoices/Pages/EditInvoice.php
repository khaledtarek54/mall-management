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
            // Apply the tenant's on-account CREDIT to this invoice. Posts its own Dr Unearned / Cr AR
            // entry dated today (ApplyTenantCreditService), so an old overpayment settles a current
            // invoice safely. Capped at the invoice balance; same-property.
            Action::make('apply_credit')
                ->label(__('admin.actions.apply_credit'))
                ->icon('heroicon-o-gift')
                ->color('gray')
                ->authorize(fn () => Auth::user()?->can('payments.edit') ?? false)
                ->visible(fn (): bool => round((float) $this->record->balance, 2) > 0
                    && $this->record->tenant !== null
                    && $this->record->tenant->creditBalance(\App\Support\TenantScope::visibleAssetIds()) > 0
                    && (Auth::user()?->can('payments.edit') ?? false))
                ->requiresConfirmation()
                ->modalDescription(fn () => __('admin.actions.apply_credit_confirm', [
                    'credit' => 'EGP '.number_format($this->record->tenant->creditBalance(\App\Support\TenantScope::visibleAssetIds()), 2),
                ]))
                ->action(function (): void {
                    abort_unless(Auth::user()?->can('payments.edit') ?? false, 403);
                    try {
                        $applied = app(\App\Services\ApplyTenantCreditService::class)->applyToInvoice($this->record);
                        $this->refreshFormData(['status', 'paid_amount', 'balance']);
                        Notification::make()
                            ->title(__('admin.notifications.credit_applied', ['amount' => 'EGP '.number_format($applied, 2)]))
                            ->success()
                            ->send();
                    } catch (\DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
            // Reverse the applied credit (undo) — soft-deletes the applications; the GL entry is
            // voided, the invoice AR re-opens, and the credit returns to the tenant's balance.
            Action::make('reverse_credit')
                ->label(__('admin.actions.reverse_credit'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->authorize(fn () => Auth::user()?->can('payments.edit') ?? false)
                ->visible(fn (): bool => \App\Models\TenantCreditApplication::where('invoice_id', $this->record->id)->exists()
                    && (Auth::user()?->can('payments.edit') ?? false))
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.reverse_credit_confirm'))
                ->action(function (): void {
                    abort_unless(Auth::user()?->can('payments.edit') ?? false, 403);
                    $reversed = app(\App\Services\ApplyTenantCreditService::class)->reverseForInvoice($this->record);
                    $this->refreshFormData(['status', 'paid_amount', 'balance']);
                    Notification::make()
                        ->title(__('admin.notifications.credit_reversed', ['amount' => 'EGP '.number_format($reversed, 2)]))
                        ->success()
                        ->send();
                }),
            // Void (cancel) an issued invoice — the supported correction now that editing is
            // locked. Reverses any applied credit + voids the GL entry; captured cash blocks it.
            Action::make('void_invoice')
                ->label(__('admin.actions.void_invoice'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => in_array($this->record->status, ['issued', 'overdue'], true)
                    && $this->record->eta_status !== 'valid' // a filed ETA tax invoice: use a credit note
                    && $this->record->capturedCashPaid() <= 0 // reversible credit (notes + tenant credit) doesn't block
                    && (Auth::user()?->can('invoices.void') ?? false))
                ->authorize(fn () => Auth::user()?->can('invoices.void') ?? false)
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
