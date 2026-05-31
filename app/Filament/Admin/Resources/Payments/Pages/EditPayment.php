<?php

namespace App\Filament\Admin\Resources\Payments\Pages;

use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Models\Payment;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPayment extends EditRecord
{
    protected static string $resource = PaymentResource::class;

    protected array $allocations = [];

    protected function getHeaderActions(): array
    {
        return [
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
        $this->guardAllocationsTotal($data);

        $this->allocations = $data['allocations'] ?? [];
        unset($data['allocations']);

        return $data;
    }

    protected function guardAllocationsTotal(array $data): void
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
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
            $sync[$invoiceId] = ['allocated_amount' => round($amount, 2)];
        }

        try {
            $payment->assertInvoicesShareTenant(array_keys($sync));
        } catch (\DomainException $e) {
            Notification::make()
                ->title(__('admin.actions.allocation_exceeds_title'))
                ->body($e->getMessage())
                ->danger()
                ->send();
            $this->halt();
        }

        $payment->invoices()->sync($sync);

        // Recompute every invoice that was ever attached so detached ones flip back to outstanding.
        $touchedIds = array_unique(array_merge($previouslyAttached, array_keys($sync)));
        \App\Models\Invoice::whereIn('id', $touchedIds)->get()->each->recomputeTotals();
    }
}
