<?php

namespace App\Filament\Admin\Resources\Payments\Pages;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Actions\ReversalReasonField;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\CapturePaymentService;
use App\Services\VoidPaymentService;
use App\Support\Filament\AnnouncesLedgerRestatement;
use App\Support\Filament\RefreshesRecordState;
use Filament\Actions\Action;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EditPayment extends EditRecord
{
    /**
     * The record and everything its hooks derive are ONE unit of work.
     *
     * Filament's `EditRecord::save()` already rolls back and re-throws on any Throwable — it is
     * inert only because `CanUseDatabaseTransactions` defers to the panel and no panel here opts
     * in, so every Create/Edit page that throws in its hooks commits the record anyway. (The
     * panel-wide question is recorded as SW-003d; this page is enabled because a partial commit
     * here is a MONEY problem, not a cosmetic one.)
     *
     * **`halt()` COMMITS by default** — `BasePage::halt(bool $shouldRollbackDatabaseTransaction =
     * false)`. So turning the transaction on is not enough on its own: every halt that follows a
     * refusal has to ask for the rollback, or the page keeps exactly the behaviour this is meant to
     * fix while looking as though it were fixed.
     */
    protected ?bool $hasDatabaseTransactions = true;

    use AnnouncesLedgerRestatement;
    use RefreshesRecordState;

    /**
     * Allocation and voiding are re-derived, never typed.
     *
     * @return array<int, string>
     */
    protected function derivedStatePaths(): array
    {
        return ['status', 'amount'];
    }

    protected static string $resource = PaymentResource::class;

    protected array $allocations = [];

    protected function getHeaderActions(): array
    {
        return [
            // **The ledger panel, on the screen where the edit happens.** The factory has existed
            // since CHANGE-IMPACT-PLAN §6.1 and was mounted on five LIST tables only — which is
            // where you audit, not where you act. An operator about to retype a figure could not
            // see what the document had already done to the books without leaving the page.
            LedgerEntryAction::make(),
            // **Capture** — the one manual transition the status field had left, made an act
            // (SW-240). `initiated` → `captured` posts the cash to the GL, and it rode on the
            // form's status dropdown with no confirmation. The audience is the gateway session
            // that died mid-flight whose money genuinely arrived, confirmed against the bank —
            // rare, which is exactly why it deserves a sentence to read before it moves the books.
            // Gated on `payments.edit`, what the Select door required, so no role's reach changes.
            Action::make('capture')
                ->label(__('admin.actions.capture_payment'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === 'initiated'
                    && (Auth::user()?->can('payments.edit') ?? false))
                ->authorize(fn () => Auth::user()?->can('payments.edit') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.capture_payment_confirm'))
                ->action(function (): void {
                    try {
                        app(CapturePaymentService::class)->capture($this->record);
                    } catch (\DomainException $e) {
                        // Not initiated any more, or a payment date in a closed period — the
                        // reason as a toast, never a Livewire 500.
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    $this->refreshFormData(['status']);
                    Notification::make()
                        ->title(__('admin.notifications.payment_captured'))
                        ->body($this->record->reference)
                        ->success()
                        ->send();
                }),
            // Void / refund a captured payment — the supported reversal now that the receipt's
            // money fields are locked. Re-opens the allocated invoices' AR + voids the GL leg.
            Action::make('void_payment')
                ->label(__('admin.actions.void_payment'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn () => $this->record->isReceived()
                    && (Auth::user()?->can('payments.void') ?? false))
                ->authorize(fn () => Auth::user()?->can('payments.void') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.void_payment_confirm'))
                ->schema([ReversalReasonField::make()])
                ->action(function (array $data): void {
                    try {
                        app(VoidPaymentService::class)->void($this->record, $data['reason'] ?? null);
                        $this->refreshFormData(['status']);
                        Notification::make()->title(__('admin.notifications.payment_voided'))->success()->send();
                    } catch (\DomainException $e) {
                        Notification::make()
                            ->title(__('admin.notifications.payment_void_failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Payment $payment */
        $payment = $this->record;
        $data['allocations'] = $payment->invoices()
            ->get()
            ->map(fn ($invoice) => [
                'invoice_id' => $invoice->id,
                'allocated_amount' => (float) $invoice->pivot->allocated_amount,
            ])
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Don't let an edit strip a receipt down to zero allocations — that orphans the money
        // (invisible in the property-scoped UI). Mirror the create guard.
        $this->guardHasAllocation($data);

        $this->guardAllocationsTotal($data);

        $this->allocations = $data['allocations'] ?? [];
        unset($data['allocations']);

        return $data;
    }

    /** Refuse leaving a receipt with no invoice allocation (orphaned money). */
    protected function guardHasAllocation(array $data): void
    {
        $hasAllocation = collect($data['allocations'] ?? [])
            ->contains(fn ($row) => ! empty($row['invoice_id']) && (float) ($row['allocated_amount'] ?? 0) > 0);

        if (! $hasAllocation) {
            Notification::make()
                ->title(__('admin.payment.allocation_required_title'))
                ->body(__('admin.payment.allocation_required_body'))
                ->danger()
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
    }

    protected function guardAllocationsTotal(array $data): void
    {
        // The amount field is locked (disabled) on a finalized payment, so it isn't in
        // the submitted data — fall back to the persisted amount so re-allocation (which
        // stays allowed) still caps against the real receipt total instead of 0.
        $amount = round((float) ($data['amount'] ?? $this->record?->amount ?? 0), 2);
        $allocated = 0.0;
        foreach ($data['allocations'] ?? [] as $row) {
            $allocated += (float) ($row['allocated_amount'] ?? 0);
        }
        $allocated = round($allocated, 2);

        if ($allocated > $amount) {
            Notification::make()
                ->title(__('admin.actions.allocation_exceeds_title'))
                ->body(__('admin.actions.allocation_exceeds_body', [
                    'allocated' => number_format($allocated, 2),
                    'amount' => number_format($amount, 2),
                ]))
                ->danger()
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
    }

    protected function afterSave(): void
    {
        /** @var Payment $payment */
        $payment = $this->record;

        $previouslyAttached = $payment->invoices()->pluck('invoices.id')->all();

        $sync = [];
        foreach ($this->allocations as $row) {
            $invoiceId = $row['invoice_id'] ?? null;
            $amount = (float) ($row['allocated_amount'] ?? 0);
            if (! $invoiceId || $amount <= 0) {
                continue;
            }
            // SUM duplicate rows for the same invoice (pivot is keyed by invoice id) — a plain
            // assignment would silently drop all but the last row. Mirrors CreatePayment.
            $sync[$invoiceId]['allocated_amount'] = round(($sync[$invoiceId]['allocated_amount'] ?? 0) + $amount, 2);
        }

        try {
            // Property isolation: every allocated invoice must be in the user's visible set.
            foreach (array_keys($sync) as $invoiceId) {
                PaymentResource::assertInvoiceAssetInScope($invoiceId);
            }
            $payment->assertInvoicesShareTenant(array_keys($sync));

            DB::transaction(function () use ($payment, $sync, $previouslyAttached) {
                $payment->invoices()->sync($sync);

                // Recompute every invoice that was ever attached so detached ones flip back to outstanding.
                $touchedIds = array_unique(array_merge($previouslyAttached, array_keys($sync)));
                Invoice::whereIn('id', $touchedIds)->get()->each->recomputeTotals();

                // Lock-safe over-allocation backstop (rolls back this sync if violated).
                $payment->assertInvoicesNotOverAllocated(array_keys($sync));

                // …and the OTHER direction, which the total-vs-amount cap above cannot see. That cap
                // asks "does this add up to the receipt", and a receipt whose surplus has already
                // been drawn down as tenant credit has less than its face value left to give. Without
                // this, re-allocating it in full spends the same money twice — once through the pivot
                // and once through the credit application that had already settled another invoice.
                $payment->assertCreditNotOverdrawn();
            });
        } catch (\DomainException $e) {
            Notification::make()
                ->title(__('admin.actions.allocation_exceeds_title'))
                ->body($e->getMessage())
                ->danger()
                ->send();
            $this->halt(shouldRollbackDatabaseTransaction: true);
        }

        // Re-allocating a payment across invoices (or to invoices on another property)
        // changes its GL entry's AR/per-asset split, but a reallocation that leaves the
        // payment's OWN columns unchanged never bumps its updated_at — so the windowed
        // sync-ledger sweep would skip it and the GL would keep the stale split. Touch
        // the payment so the sweep re-derives it on the next run. (Phase 0, F8.)
        $payment->touch();

        // Allocations are now synced — deliver the receipt notification.
        // AFTER THE COMMIT, for the reason CreatePayment gives: the allocation guard holds
        // `lockForUpdate()` on the invoice and four settlement tables, and
        // `PaymentReceivedNotification` is not `ShouldQueue` — its mail channel sends synchronously,
        // per portal user. Inside the transaction every other settlement against that invoice queues
        // behind an SMTP round-trip.
        DB::afterCommit(fn () => $payment->notifyReceiptOnce());
    }
}
