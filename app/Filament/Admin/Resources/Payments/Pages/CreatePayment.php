<?php

namespace App\Filament\Admin\Resources\Payments\Pages;

use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Models\Payment;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    protected array $allocations = [];

    protected function mutateFormDataBeforeCreate(array $data): array
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

    protected function afterCreate(): void
    {
        /** @var Payment $payment */
        $payment = $this->record;

        $sync = [];
        foreach ($this->allocations as $row) {
            $invoiceId = $row['invoice_id'] ?? null;
            $amount = (float) ($row['allocated_amount'] ?? 0);
            if (! $invoiceId || $amount <= 0) {
                continue;
            }
            $sync[$invoiceId] = ['allocated_amount' => round($amount, 2)];
        }

        if (! empty($sync)) {
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
            \Illuminate\Support\Facades\DB::transaction(function () use ($payment, $sync) {
                $payment->invoices()->sync($sync);
                $payment->recomputeAllocatedInvoices();
            });

            // Allocations are now synced — deliver the receipt notification.
            $payment->notifyReceiptOnce();
        }
    }
}
