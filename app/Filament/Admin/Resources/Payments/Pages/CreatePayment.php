<?php

namespace App\Filament\Admin\Resources\Payments\Pages;

use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Payment;
use App\Support\PostingDate;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    protected array $allocations = [];

    /**
     * Open with the tenant already chosen when the collections worklist sent us here (UX5-03).
     *
     * The daily loop is "call the tenant → they say they paid → record it", and it cost six screens:
     * worklist → tenant hub → sidebar → Payments → New → search the SAME tenant again → amount. The
     * form itself was never the problem; reaching it with the context you already had was. With the
     * tenant filled, typing the amount fires `suggestAllocations()` on blur and the receipt is
     * spread oldest-first, which is the whole of the job.
     *
     * **The id is re-checked against the reader's own scoped query, not trusted.** It arrives in a
     * query string, so a hand-typed one could name a tenant in a mall this user cannot see. The
     * EntitySelect would refuse it at validation anyway — Filament resolves a Select's value by
     * asking for its LABEL through the scoped query — but prefilling a value the form will later
     * reject presents as the page being broken rather than as a refusal, so it is dropped here.
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $tenantId = (int) request()->query('tenant', 0);

        $this->form->fill(
            $tenantId > 0 && TenantResource::getEloquentQuery()->whereKey($tenantId)->exists()
                ? ['tenant_id' => $tenantId]
                : null,
        );

        $this->callHook('afterFill');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Posting-date guard: a receipt back-dated into a CLOSED accounting period would relieve
        // AR here while its GL cash/AR leg can never post (silent divergence — the exact shape
        // App\Support\PostingDate exists to stop). Refuse in the service layer, not just a
        // DatePicker minDate. A missing period is allowed (see PostingDate).
        try {
            PostingDate::assertOpen($data['payment_date'] ?? null, __('admin.fields.payment_date'));
        } catch (\DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $this->halt();
        }

        // A receipt with NO allocation is orphaned money: it posts as an unearned-revenue advance
        // but the property-scoped UI (which scopes payments via their invoices) can never surface
        // it again to apply it. Require at least one allocation (surfacing + auto-applying a tenant
        // credit balance is a deferred feature — see the closure ledger). Server-side backstop to
        // the Repeater's minItems(1).
        $this->guardHasAllocation($data);

        $this->guardAllocationsTotal($data);

        // Property isolation: every allocated invoice must be in the user's visible set.
        // Guard BEFORE the payment row is created (the picker is scoped, but re-validate
        // the submitted ids — a shared tenant may have invoices across properties).
        foreach ($data['allocations'] ?? [] as $row) {
            if (! empty($row['invoice_id'])) {
                PaymentResource::assertInvoiceAssetInScope($row['invoice_id']);
            }
        }

        // Who took the money. `payments.received_by` existed, the receipt PDF renders it, and
        // `PostDatedChequeService` set it — but the ORDINARY path never did, so the most common
        // receipt of all (cash or transfer at the counter) silently omitted the line. The column
        // was there and only one of its two writers had been built.
        //
        // Stamped from the authenticated user rather than asked for: whoever is recording the
        // receipt is who received it, and a form field would be one more thing to fill in wrongly.
        $data['received_by'] ??= Auth::id();

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

    /** Refuse a receipt with no invoice allocation (see mutateFormDataBeforeCreate). */
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
            // SUM duplicate rows for the same invoice — the pivot is keyed by invoice id, so a
            // plain assignment would let a second row silently overwrite the first (only the last
            // applied, while the form summary reported both as allocated → stranded money).
            $sync[$invoiceId]['allocated_amount'] = round(($sync[$invoiceId]['allocated_amount'] ?? 0) + $amount, 2);
        }

        if (! empty($sync)) {
            try {
                $payment->assertInvoicesShareTenant(array_keys($sync));
                DB::transaction(function () use ($payment, $sync) {
                    $payment->invoices()->sync($sync);
                    $payment->recomputeAllocatedInvoices();
                    // Lock-safe backstop: the form cap is per-request; this catches
                    // a parallel capture that would push the invoice over its total.
                    $payment->assertInvoicesNotOverAllocated(array_keys($sync));
                });
            } catch (\DomainException $e) {
                $payment->delete(); // undo the orphan payment row created before this hook
                Notification::make()
                    ->title(__('admin.actions.allocation_exceeds_title'))
                    ->body($e->getMessage())
                    ->danger()
                    ->send();
                $this->halt();
            }

            // Allocations are now synced — deliver the receipt notification.
            $payment->notifyReceiptOnce();
        }
    }
}
