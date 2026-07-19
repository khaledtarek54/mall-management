<?php

namespace App\Filament\Admin\Resources\Payments\Pages;

use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Models\Payment;
use App\Services\VoidPaymentService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditPayment extends EditRecord
{
    protected static string $resource = PaymentResource::class;

    protected array $allocations = [];

    protected function getHeaderActions(): array
    {
        return [
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
                ->schema([
                    Textarea::make('reason')
                        ->label(__('admin.fields.void_reason'))
                        ->required()
                        ->maxLength(500),
                ])
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
            DeleteAction::make(),
            ForceDeleteAction::make(),
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

            $this->halt();
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

            $this->halt();
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

            \Illuminate\Support\Facades\DB::transaction(function () use ($payment, $sync, $previouslyAttached) {
                $payment->invoices()->sync($sync);

                // Recompute every invoice that was ever attached so detached ones flip back to outstanding.
                $touchedIds = array_unique(array_merge($previouslyAttached, array_keys($sync)));
                \App\Models\Invoice::whereIn('id', $touchedIds)->get()->each->recomputeTotals();

                // Lock-safe over-allocation backstop (rolls back this sync if violated).
                $payment->assertInvoicesNotOverAllocated(array_keys($sync));
            });
        } catch (\DomainException $e) {
            Notification::make()
                ->title(__('admin.actions.allocation_exceeds_title'))
                ->body($e->getMessage())
                ->danger()
                ->send();
            $this->halt();
        }

        // Re-allocating a payment across invoices (or to invoices on another property)
        // changes its GL entry's AR/per-asset split, but a reallocation that leaves the
        // payment's OWN columns unchanged never bumps its updated_at — so the windowed
        // sync-ledger sweep would skip it and the GL would keep the stale split. Touch
        // the payment so the sweep re-derives it on the next run. (Phase 0, F8.)
        $payment->touch();

        // Allocations are now synced — deliver the receipt notification.
        $payment->notifyReceiptOnce();
    }
}
