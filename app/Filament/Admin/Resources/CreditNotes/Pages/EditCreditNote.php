<?php

namespace App\Filament\Admin\Resources\CreditNotes\Pages;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Actions\ReversalReasonField;
use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\CreditNotePdfService;
use App\Services\CreditNoteService;
use App\Support\Filament\AnnouncesLedgerRestatement;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PdfDownloadAction;
use App\Support\Filament\RefreshesRecordState;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Auth;

class EditCreditNote extends EditRecord
{
    use AnnouncesLedgerRestatement;
    use RefreshesRecordState;

    /**
     * Un-applying an application from the relation manager below re-opens this note's balance
     * and can move it back out of `applied`. The figures are on this form; the button is not.
     *
     * @return array<int, string>
     */
    protected function derivedStatePaths(): array
    {
        return ['status', 'applied_amount', 'balance'];
    }

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

    /** The most that can be applied: capped at the LESSER of the note's balance and the chosen
     * invoice's balance, so the operator can't try to over-apply an invoice (the service caps it too,
     * but this guides them in the modal instead of only correcting it after). */
    private function applyCap(mixed $invoiceId): float
    {
        $noteBalance = (float) $this->record->balance;
        if (! $invoiceId) {
            return $noteBalance;
        }

        return min($noteBalance, (float) (Invoice::find($invoiceId)?->balance ?? 0));
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
                ->service(CreditNotePdfService::class)
                ->recipient(fn (CreditNote $record) => $record->tenant)
                ->authorize(fn () => Auth::user()?->can('credit_notes.view') ?? false),

            Action::make('issue')
                ->label(__('admin.actions.issue_credit_note'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === 'draft'
                    && Auth::user()?->can('credit_notes.issue'))
                ->authorize(fn () => Auth::user()?->can('credit_notes.issue') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.issue_credit_note_confirm'))
                ->action(function (): void {
                    try {
                        app(CreditNoteService::class)->issue($this->record);
                    } catch (\DomainException $e) {
                        // e.g. issuing into a CLOSED accounting period (PostingDate guard) — show the
                        // localized reason as a clean toast instead of an uncaught Livewire 500.
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
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
                    // The outstanding figure in the label — which this screen invented and which is
                    // the whole reason an operator can choose the right invoice — is now every
                    // invoice picker's, from OptionDisplay. Property scope likewise.
                    EntitySelect::make('invoice_id')
                        ->label(__('admin.fields.invoice'))
                        ->entity(Invoice::class)
                        ->modifyOptionsQuery(fn ($query) => $query
                            ->where('tenant_id', $this->record->tenant_id)
                            ->where('balance', '>', 0)
                            ->whereIn('status', ['issued', 'partially_paid', 'overdue']))
                        // BROWSE, don't guess. `Invoice` is deliberately absent from
                        // `OptionDisplay::PRELOAD` — a portfolio holds thousands and loading them
                        // all into a dropdown is the wrong default. It is the wrong default HERE,
                        // though: the query above has already narrowed to ONE tenant's OPEN
                        // invoices, which is bounded by the shape of the business (a handful), and
                        // that is exactly the case CLAUDE.md says opts in per call site.
                        //
                        // Found in the panel (2026-08-25): a credit note whose own `invoice_id`
                        // already named the invoice opened a picker showing NOTHING, over a tenant
                        // with exactly one open invoice. An empty dropdown reads as "no such
                        // record", which is indistinguishable from a bug — and it is the reason
                        // nobody reports it. `CreditNoteForm` had already reached this conclusion
                        // and preloaded; the other three had not, so the same picker behaved two
                        // ways in one module. Yardi shows the open invoices and you pick one.
                        ->preload()
                        ->required()
                        // A credit note usually names its invoice when it is raised, and applying it
                        // to that invoice is overwhelmingly the case. Offered as a DEFAULT, never
                        // forced: re-checked against the same query the picker uses, so a note whose
                        // invoice was since settled or cancelled opens blank rather than pre-filled
                        // with an option the picker would refuse at validation.
                        ->default(fn () => Invoice::query()
                            ->whereKey($this->record->invoice_id)
                            ->where('tenant_id', $this->record->tenant_id)
                            ->where('balance', '>', 0)
                            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                            ->value('id'))
                        ->live()
                        // Pre-fill the amount with the cap for the chosen invoice (min of note + invoice
                        // balance), so the common "apply all that fits" is one click and never over-applies.
                        ->afterStateUpdated(fn ($state, Set $set) => $set('amount', $this->applyCap($state))),
                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(fn (Get $get) => $this->applyCap($get('invoice_id')))
                        ->default(fn () => (float) $this->record->balance)
                        ->helperText(fn (Get $get) => __('admin.actions.apply_amount_helper', ['max' => number_format($this->applyCap($get('invoice_id')), 2)])),
                ])
                ->action(function (array $data): void {
                    $invoice = Invoice::findOrFail($data['invoice_id']);

                    // Defense-in-depth against form tampering: the picker is scoped, but a crafted
                    // submit can pass any id — re-validate BOTH the property (never credit a property
                    // the user can't see) AND the tenant (never pay down another tenant's invoice).
                    $visibleAssetIds = TenantScope::visibleAssetIds();
                    // `asset_id`, not the lease chain — the chain is null for a unit-owner assessment,
                    // and `in_array(null, [...])` is false, so crediting one was a bare 403 from this page.
                    if ($visibleAssetIds !== null
                        && ! in_array($invoice->asset_id, $visibleAssetIds, true)) {
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
                ->schema([ReversalReasonField::make()])
                ->action(function (array $data): void {
                    abort_unless(Auth::user()?->can('credit_notes.apply') ?? false, 403);
                    $reversed = app(CreditNoteService::class)->reverseAllApplications($this->record, $data['reason'] ?? null);
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
                ->schema([ReversalReasonField::make()])
                ->action(function (array $data): void {
                    try {
                        app(CreditNoteService::class)->void($this->record, $data['reason'] ?? null);
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

            // Hidden once credit is applied — deleting would throw (the model refuses, to avoid AR
            // drift); the guided way out is Reverse (visible in that case). Delete stays super_admin-only.
        ];
    }
}
