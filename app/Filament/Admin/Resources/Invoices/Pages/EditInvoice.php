<?php

namespace App\Filament\Admin\Resources\Invoices\Pages;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Actions\ReversalReasonField;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Models\DepositApplication;
use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use App\Models\TenantCreditApplication;
use App\Services\ApplyDepositToInvoiceService;
use App\Services\ApplyTenantCreditService;
use App\Services\InvoicePdfService;
use App\Services\SendInvoiceToTenantService;
use App\Services\VoidInvoiceService;
use App\Services\WriteOffInvoiceService;
use App\Support\Filament\AnnouncesLedgerRestatement;
use App\Support\Filament\PdfDownloadAction;
use App\Support\Filament\RefreshesRecordState;
use App\Support\OpsLog;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class EditInvoice extends EditRecord
{
    use AnnouncesLedgerRestatement;
    use RefreshesRecordState;

    /**
     * Everything about this invoice that is DERIVED — recomputed by `Invoice::recomputeTotals()`
     * from the four settlement channels, never typed. A payment recorded against this invoice
     * from another surface, or a credit note applied to it, moves all three.
     *
     * @return array<int, string>
     */
    protected function derivedStatePaths(): array
    {
        return ['status', 'paid_amount', 'balance'];
    }

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
            // **The ledger panel, on the screen where the edit happens.** The factory has existed
            // since CHANGE-IMPACT-PLAN §6.1 and was mounted on five LIST tables only — which is
            // where you audit, not where you act. An operator about to retype a figure could not
            // see what the document had already done to the books without leaving the page.
            LedgerEntryAction::make(),
            PdfDownloadAction::make('downloadPdf')
                ->label(__('admin.actions.download_pdf'))
                ->service(InvoicePdfService::class)
                ->recipient(fn (Invoice $record) => $record->tenant)
                ->authorize(fn () => Auth::user()?->can('invoices.view') ?? false),
            // UX5-09. Until this shipped, the ONLY invoice a tenant was ever emailed was one the
            // monthly run raised: a violation fine, a CAM recovery, an NSF fee or anything an
            // operator typed reached them only if they opened the portal — and there was no way to
            // re-send the one they say never arrived, so the answer was to download the PDF and mail
            // it by hand. Labelled by whether it has gone before, because "Send" and "Send again"
            // are different decisions and the operator is entitled to know which one they are making.
            Action::make('sendToTenant')
                ->label(fn () => $this->record->tenant_notified_at
                    ? __('admin.actions.resend_invoice')
                    : __('admin.actions.send_invoice'))
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->record->tenant_notified_at
                    ? __('admin.invoices.resend_confirm', [
                        'when' => $this->record->tenant_notified_at->translatedFormat('d M Y H:i'),
                    ])
                    : __('admin.invoices.send_confirm'))
                // A draft is not a document — the tenant cannot see one anywhere else either, so
                // offering to email it would be the one surface that leaks it.
                ->visible(fn () => $this->record->isVisibleToTenant())
                ->authorize(fn () => Auth::user()?->can('invoices.view') ?? false)
                ->action(function () {
                    abort_unless(Auth::user()?->can('invoices.view') ?? false, 403);

                    $sent = app(SendInvoiceToTenantService::class)->send($this->record);

                    if (! $sent) {
                        Notification::make()
                            ->warning()
                            ->title(__('admin.invoices.send_no_tenant'))
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(__('admin.invoices.sent'))
                        ->send();
                }),
            Action::make('paymentLink')
                ->label(__('admin.actions.payment_link'))
                ->icon('heroicon-o-link')
                ->color('gray')
                ->authorize(fn () => Auth::user()?->can('invoices.view') ?? false)
                // Shown whenever a token exists — NOT only while the gateway is on and the invoice
                // payable, which is how it used to read. The token is minted on every invoice and
                // the mobile API already hands the URL to the tenant, so the link is live either
                // way; gating the ONE screen that displays it on PAYMOB_ENABLED left the operator
                // able to revoke a bearer credential they could not read. The modal says which
                // state it is in.
                ->visible(fn () => filled($this->record->payment_link_token)
                    && (Auth::user()?->can('invoices.view') ?? false))
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
            // A netted security deposit needs the same escape hatch as an applied credit (MF-03):
            // an operator who settles a move-out against the wrong invoice must be able to undo it,
            // and the money records are never deletable. Reversing re-opens the AR and returns the
            // deposit balance.
            Action::make('reverse_deposit_application')
                ->label(__('admin.actions.reverse_deposit_application'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->authorize(fn () => Auth::user()?->can('payments.edit') ?? false)
                ->visible(fn (): bool => DepositApplication::where('invoice_id', $this->record->id)->exists()
                    && (Auth::user()?->can('payments.edit') ?? false))
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.reverse_deposit_application_confirm'))
                ->schema([ReversalReasonField::make()])
                ->action(function (array $data): void {
                    abort_unless(Auth::user()?->can('payments.edit') ?? false, 403);

                    $svc = app(ApplyDepositToInvoiceService::class);
                    $reversed = 0.0;

                    try {
                        foreach (DepositApplication::where('invoice_id', $this->record->id)->get() as $application) {
                            $reversed += (float) $application->amount;
                            $svc->reverse($application, $data['reason'] ?? null);
                        }
                    } catch (\DomainException $e) {
                        // e.g. the GL void lands in a CLOSED period — clean toast, not a 500.
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    // The netted deposit is one of the four settlement channels, so reversing it
                    // re-opens this invoice's AR — the same three fields its four sibling actions
                    // refresh. This one did not, so the balance on screen stayed settled.
                    $this->refreshFormData(['status', 'paid_amount', 'balance']);

                    Notification::make()
                        ->success()
                        ->title(__('admin.actions.reverse_deposit_application_done', [
                            'amount' => 'EGP '.number_format($reversed, 2),
                        ]))
                        ->send();
                }),
            Action::make('reverse_credit')
                ->label(__('admin.actions.reverse_credit'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->authorize(fn () => Auth::user()?->can('payments.edit') ?? false)
                ->visible(fn (): bool => TenantCreditApplication::where('invoice_id', $this->record->id)->exists()
                    && (Auth::user()?->can('payments.edit') ?? false))
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.reverse_credit_confirm'))
                ->schema([ReversalReasonField::make()])
                ->action(function (array $data): void {
                    abort_unless(Auth::user()?->can('payments.edit') ?? false, 403);
                    try {
                        $reversed = app(ApplyTenantCreditService::class)->reverseForInvoice($this->record, $data['reason'] ?? null);
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
            // **Bad-debt recovery** — the tenant paid after all.
            //
            // `WriteOffInvoiceService::reverse()` was written, tested and reachable from nothing but
            // two test files (found 2026-08-28). Recovery is an ordinary event: a tenant leaves owing
            // 48,000, it is written off at year-end, and eighteen months later their lawyer settles.
            // Without this button the only paths were to raise a NEW invoice for money already billed
            // once — double-counting the revenue — or to book the receipt as miscellaneous income,
            // which loses the tenant's AR history. Voyager reverses the write-off and receipts against
            // the re-opened charge; this is that.
            //
            // Reverses the write-offs one at a time so a PARTIAL write-off recovers in full: the
            // service re-opens the invoice on the first one and is a no-op on the rest.
            Action::make('reverse_write_off')
                ->label(__('admin.actions.reverse_write_off'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (): bool => InvoiceWriteOff::where('invoice_id', $this->record->id)->exists()
                    && (Auth::user()?->can('invoices.void') ?? false))
                ->authorize(fn () => Auth::user()?->can('invoices.void') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.reverse_write_off_confirm'))
                ->schema([ReversalReasonField::make()])
                ->action(function (array $data): void {
                    abort_unless(Auth::user()?->can('invoices.void') ?? false, 403);

                    // Named for the service, not `$svc`: this page resolves four money services and
                    // two of them have a `reverse()`. A shared variable name makes the call
                    // ambiguous to anything reading the source — including the method-level
                    // reachability gate, which then cannot tell whose `reverse()` this is.
                    $writeOffs = app(WriteOffInvoiceService::class);
                    $recovered = 0.0;

                    try {
                        foreach (InvoiceWriteOff::where('invoice_id', $this->record->id)->get() as $writeOff) {
                            $recovered += (float) $writeOff->amount;
                            $writeOffs->reverse($writeOff, $data['reason'] ?? null);
                        }
                    } catch (\DomainException $e) {
                        // e.g. the GL void lands in a CLOSED period — a toast, not a 500, exactly as
                        // its two sibling reversals on this page do.
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    // The invoice's AR re-opens, so the same three fields the sibling reversals
                    // refresh have all moved.
                    $this->refreshFormData(['status', 'paid_amount', 'balance']);

                    Notification::make()
                        ->success()
                        ->title(__('admin.notifications.write_off_reversed', [
                            'amount' => 'EGP '.number_format($recovered, 2),
                        ]))
                        ->send();
                }),
            // Void (cancel) an issued invoice — the supported correction now that editing is
            // locked. Reverses any applied credit + voids the GL entry; captured cash blocks it.
            // Write off, NOT void. Voiding reverses the revenue in the current period — including
            // revenue earned in a prior year — so the year it was actually earned is understated
            // and the loss never appears as bad debt. A write-off keeps the revenue where it was
            // earned and books the loss where it was recognised.
            Action::make('write_off')
                ->label(__('admin.actions.write_off_invoice'))
                ->icon('heroicon-o-receipt-percent')
                ->color('danger')
                ->visible(fn () => (float) $this->record->balance > 0
                    && ! in_array($this->record->status, ['draft', 'cancelled', 'written_off'], true)
                    && (Auth::user()?->can('invoices.void') ?? false))
                ->authorize(fn () => Auth::user()?->can('invoices.void') ?? false)
                ->modalDescription(__('admin.actions.write_off_invoice_confirm'))
                ->schema([
                    TextInput::make('amount')
                        ->label(__('admin.fields.write_off_amount'))
                        ->numeric()
                        ->prefix('EGP')
                        ->minValue(0.01)
                        // Defaults to what is LEFT to write off, not the raw balance. A partial
                        // write-off deliberately leaves `balance` standing, so offering the
                        // balance again on the second pass invited an operator to accept a
                        // default that writes off more than the debt. The service refuses it
                        // either way; this stops the modal proposing it.
                        ->default(fn () => round((float) $this->record->balance - $this->record->writtenOffAmount(), 2))
                        ->maxValue(fn () => round((float) $this->record->balance - $this->record->writtenOffAmount(), 2))
                        ->helperText(fn () => $this->record->writtenOffAmount() > 0
                            ? __('admin.helpers.write_off_already', [
                                'amount' => number_format($this->record->writtenOffAmount(), 2),
                                'balance' => number_format((float) $this->record->balance, 2),
                            ])
                            : null)
                        ->required(),
                    DatePicker::make('entry_date')
                        ->label(__('admin.fields.write_off_date'))
                        ->native(false)
                        ->default(now())
                        ->required()
                        ->helperText(__('admin.helpers.write_off_date'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.write_off_date')),
                    Select::make('reason')
                        ->label(__('admin.fields.write_off_reason'))
                        ->options(collect(InvoiceWriteOff::REASONS)
                            ->mapWithKeys(fn (string $r) => [$r => __("admin.write_off_reasons.{$r}")])->all())
                        ->native(false)
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    // action() is the real gate; visible() is the UI.
                    abort_unless(Auth::user()?->can('invoices.void') ?? false, 403);

                    try {
                        app(WriteOffInvoiceService::class)->write($this->record, $data);
                        $this->refreshFormData(['status', 'balance']);
                        Notification::make()->title(__('admin.notifications.invoice_written_off'))->success()->send();
                    } catch (\DomainException $e) {
                        Notification::make()
                            ->title(__('admin.notifications.invoice_write_off_failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
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
                ->schema([ReversalReasonField::make()])
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
            RestoreAction::make(),
        ];
    }
}
