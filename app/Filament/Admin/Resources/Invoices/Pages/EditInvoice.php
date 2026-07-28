<?php

namespace App\Filament\Admin\Resources\Invoices\Pages;

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Models\TenantCreditApplication;
use App\Services\ApplyTenantCreditService;
use App\Services\InvoicePdfService;
use App\Services\VoidInvoiceService;
use App\Support\OpsLog;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

    /** The tenant's on-account credit available for THIS property (isolation-scoped). */
    private function availableCredit(): float
    {
        return $this->record->tenant?->creditBalance(TenantScope::visibleAssetIds()) ?? 0.0;
    }

    /** The most that can be applied to this invoice: min(invoice balance, available credit). */
    private function creditCap(): float
    {
        return round(min((float) $this->record->balance, $this->availableCredit()), 2);
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
                        fn () => print ($pdf),
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
            // Kill a leaked pay link. See the identical action on the invoice table for
            // why a bearer URL with no expiry needs a revocation path.
            Action::make('regeneratePaymentLink')
                ->label(__('admin.actions.regenerate_payment_link'))
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.regenerate_payment_link_confirm'))
                ->authorize(fn () => Auth::user()?->can('invoices.edit') ?? false)
                ->visible(fn () => filled($this->record->payment_link_token)
                    && (Auth::user()?->can('invoices.edit') ?? false))
                ->action(function (): void {
                    abort_unless(Auth::user()?->can('invoices.edit') ?? false, 403);

                    $this->record->rotatePaymentLinkToken();

                    OpsLog::info('invoice.pay_link_rotated', [
                        'invoice_id' => $this->record->id,
                        'invoice_number' => $this->record->number,
                        'by' => Auth::id(),
                    ]);

                    Notification::make()
                        ->title(__('admin.actions.regenerate_payment_link_done'))
                        ->body(__('admin.actions.regenerate_payment_link_done_body', ['number' => $this->record->number]))
                        ->success()
                        ->send();
                }),
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
                    && $this->record->tenant->creditBalance(TenantScope::visibleAssetIds()) > 0
                    && (Auth::user()?->can('payments.edit') ?? false))
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.apply_credit_confirm'))
                ->schema([
                    // Show the operator the actual figures BEFORE they confirm — the invoice balance and
                    // the credit available — so "Apply" is never a leap of faith (previously the modal
                    // implied the whole credit went on, when only min(credit, balance) does).
                    Placeholder::make('credit_summary')
                        ->hiddenLabel()
                        ->content(fn () => __('admin.actions.apply_credit_summary', [
                            'balance' => 'EGP '.number_format((float) $this->record->balance, 2),
                            'credit' => 'EGP '.number_format($this->availableCredit(), 2),
                        ])),
                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(fn () => $this->creditCap())     // can't over-apply the invoice or the credit
                        ->default(fn () => $this->creditCap())      // default = apply all that fits
                        ->required()
                        ->helperText(fn () => __('admin.actions.apply_credit_amount_helper', [
                            'max' => number_format($this->creditCap(), 2),
                        ])),
                ])
                ->action(function (array $data): void {
                    abort_unless(Auth::user()?->can('payments.edit') ?? false, 403);
                    try {
                        $applied = app(ApplyTenantCreditService::class)
                            ->applyToInvoice($this->record, isset($data['amount']) ? (float) $data['amount'] : null);
                    } catch (\DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    $this->refreshFormData(['status', 'balance']);
                    Notification::make()
                        ->title(__('admin.notifications.credit_applied', ['amount' => 'EGP '.number_format($applied, 2)]))
                        ->body(__('admin.notifications.credit_applied_body', [
                            'balance' => 'EGP '.number_format((float) $this->record->fresh()->balance, 2),
                            'remaining' => 'EGP '.number_format($this->availableCredit(), 2),
                        ]))
                        ->success()
                        ->send();
                }),
            // Reverse the applied credit (undo) — soft-deletes the applications; the GL entry is
            // voided, the invoice AR re-opens, and the credit returns to the tenant's balance.
            Action::make('reverse_credit')
                ->label(__('admin.actions.reverse_credit'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->authorize(fn () => Auth::user()?->can('payments.edit') ?? false)
                ->visible(fn (): bool => TenantCreditApplication::where('invoice_id', $this->record->id)->exists()
                    && (Auth::user()?->can('payments.edit') ?? false))
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.reverse_credit_confirm'))
                ->action(function (): void {
                    abort_unless(Auth::user()?->can('payments.edit') ?? false, 403);
                    try {
                        $reversed = app(ApplyTenantCreditService::class)->reverseForInvoice($this->record);
                    } catch (\DomainException $e) {
                        // e.g. the GL void lands in a CLOSED period — clean toast, not a 500
                        // (mirrors apply_credit above).
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    $this->refreshFormData(['status', 'balance']);
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
