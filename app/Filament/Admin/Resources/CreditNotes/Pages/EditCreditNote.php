<?php

namespace App\Filament\Admin\Resources\CreditNotes\Pages;

use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\CreditNoteService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditCreditNote extends EditRecord
{
    protected static string $resource = CreditNoteResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Block re-homing via a tampered lease_id (property is derived from the lease).
        if (! empty($data['lease_id'])) {
            CreditNoteResource::assertAssetInScope(
                Lease::with('unit')->find($data['lease_id'])?->unit?->asset_id
            );
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // Editing is only possible while draft (finalized notes lock their items + don't dehydrate
        // the derived money fields). Re-derive the totals from the now-persisted items so a tampered
        // submit can't carry a fabricated total into issuance.
        if ($this->record->status === 'draft') {
            $this->record->recomputeFromItems();
            $this->record->saveQuietly();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label(__('admin.actions.download_pdf'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->authorize(fn () => Auth::user()?->can('credit_notes.view') ?? false)
                ->action(function () {
                    $svc = app(\App\Services\CreditNotePdfService::class);
                    $pdf = $svc->build($this->record);

                    return response()->streamDownload(
                        fn () => print($pdf),
                        $svc->filename($this->record),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),

            Action::make('issue')
                ->label(__('admin.actions.issue_credit_note'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === 'draft'
                    && Auth::user()?->can('credit_notes.issue'))
                ->authorize(fn () => Auth::user()?->can('credit_notes.issue') ?? false)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(CreditNoteService::class)->issue($this->record);
                    $this->refreshFormData(['status', 'balance']);
                    Notification::make()
                        ->title(__('admin.notifications.credit_note_issued'))
                        ->body($this->record->number)
                        ->success()
                        ->send();
                }),

            Action::make('apply')
                ->label(__('admin.actions.apply_to_invoice'))
                ->icon('heroicon-o-arrow-right-circle')
                ->color('primary')
                ->visible(fn () => $this->record->hasBalance() && $this->record->status !== 'void'
                    && Auth::user()?->can('credit_notes.apply'))
                ->authorize(fn () => Auth::user()?->can('credit_notes.apply') ?? false)
                ->schema([
                    Select::make('invoice_id')
                        ->label(__('admin.fields.invoice'))
                        ->options(function () {
                            // Property-scope: never offer an invoice from a property the
                            // current user cannot see (a shared tenant may have invoices
                            // across properties). null = unrestricted (super_admin/portfolio).
                            $visibleAssetIds = \App\Support\TenantScope::visibleAssetIds();

                            return Invoice::query()
                                ->where('tenant_id', $this->record->tenant_id)
                                ->where('balance', '>', 0)
                                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                                ->when($visibleAssetIds !== null, fn ($q) => $q->whereHas(
                                    'lease.unit',
                                    fn ($u) => $u->whereIn('asset_id', $visibleAssetIds),
                                ))
                                ->orderByDesc('issue_date')
                                ->get()
                                ->mapWithKeys(fn ($i) => [$i->id => $i->number . ' — EGP ' . number_format((float) $i->balance, 2) . ' ' . __('admin.tables.invoice.balance')])
                                ->all();
                        })
                        ->required()
                        ->searchable(),
                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(fn () => (float) $this->record->balance)
                        ->default(fn () => (float) $this->record->balance)
                        ->helperText(__('admin.actions.apply_amount_helper', ['max' => number_format((float) $this->record->balance, 2)])),
                ])
                ->action(function (array $data): void {
                    $invoice = Invoice::findOrFail($data['invoice_id']);

                    // Defense-in-depth against form tampering: the picker is scoped, but a crafted
                    // submit can pass any id — re-validate BOTH the property (never credit a property
                    // the user can't see) AND the tenant (never pay down another tenant's invoice).
                    $visibleAssetIds = \App\Support\TenantScope::visibleAssetIds();
                    if ($visibleAssetIds !== null
                        && ! in_array($invoice->lease?->unit?->asset_id, $visibleAssetIds, true)) {
                        abort(403);
                    }
                    if ((int) $invoice->tenant_id !== (int) $this->record->tenant_id) {
                        abort(403);
                    }

                    try {
                        $applied = app(CreditNoteService::class)
                            ->applyToInvoice($this->record, $invoice, (float) $data['amount']);
                    } catch (\DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        return;
                    }

                    if ($applied <= 0) {
                        Notification::make()
                            ->title(__('admin.notifications.credit_note_apply_failed'))
                            ->warning()
                            ->send();
                        return;
                    }

                    $this->refreshFormData(['status', 'applied_amount', 'balance']);

                    Notification::make()
                        ->title(__('admin.notifications.credit_note_applied'))
                        ->body(__('admin.notifications.credit_note_applied_body', [
                            'amount' => number_format($applied, 2),
                            'invoice' => $invoice->number,
                        ]))
                        ->success()
                        ->send();
                }),

            // Guided reversal of an APPLIED note (the void dead-end's supported way out): un-apply
            // every application, re-opening the invoices' AR and returning the note to available.
            // Double-gated on credit_notes.apply in visible() AND action().
            Action::make('reverse')
                ->label(__('admin.actions.reverse_credit_note'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn () => (float) $this->record->applied_amount > 0
                    && $this->record->status !== 'void'
                    && Auth::user()?->can('credit_notes.apply'))
                ->authorize(fn () => Auth::user()?->can('credit_notes.apply') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.reverse_credit_note_confirm'))
                ->action(function (): void {
                    abort_unless(Auth::user()?->can('credit_notes.apply') ?? false, 403);
                    $reversed = app(CreditNoteService::class)->reverseAllApplications($this->record);
                    $this->refreshFormData(['status', 'applied_amount', 'balance']);
                    Notification::make()
                        ->title(__('admin.notifications.credit_note_reversed', ['amount' => number_format($reversed, 2)]))
                        ->success()
                        ->send();
                }),

            Action::make('void')
                ->label(__('admin.actions.void_credit_note'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => in_array($this->record->status, ['draft', 'issued']) && (float) $this->record->applied_amount <= 0
                    && Auth::user()?->can('credit_notes.void'))
                ->authorize(fn () => Auth::user()?->can('credit_notes.void') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.void_credit_note_confirm'))
                ->action(function (): void {
                    try {
                        app(CreditNoteService::class)->void($this->record);
                        $this->refreshFormData(['status', 'balance']);
                        Notification::make()
                            ->title(__('admin.notifications.credit_note_voided'))
                            ->success()
                            ->send();
                    } catch (\DomainException $e) {
                        Notification::make()
                            ->title($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            DeleteAction::make(),
        ];
    }
}
