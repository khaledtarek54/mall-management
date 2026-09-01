<?php

namespace App\Filament\Admin\Resources\Payments\Pages;

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
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
    /**
     * The receipt and its allocations are ONE unit of work.
     *
     * `afterCreate()` runs INSIDE Filament's own try (`CreateRecord::create()`), which already
     * rolls back and re-throws on any Throwable — it was simply inert, because
     * `CanUseDatabaseTransactions` defaults to the panel and no panel here opts in. So this one
     * property is the whole mechanism, rather than a hand-rolled wrapper that would have to
     * re-implement Filament's semantics (and, in the first attempt at this fix, got them wrong:
     * swallowing the refusal left `$this->record` pointing at a rolled-back row, Livewire
     * dehydrated it with a key, and the operator's very next keystroke 404'd on `firstOrFail()`).
     *
     * What it replaced: `afterCreate()` compensated for a refused allocation with
     * `$payment->delete()`, and this form DEFAULTS to `captured` — a receipt that is on the books,
     * which `RefusesDeletionOfCommittedRecords` refuses. The compensation threw its own exception,
     * the operator saw the DELETION refusal instead of the allocation error, and the orphan
     * survived with no allocations, reading as unallocated tenant credit.
     */
    protected ?bool $hasDatabaseTransactions = true;

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
     * **`?invoice=` goes one step further and fills the ALLOCATION too**, because the other half of
     * the same loop is "the tenant paid THIS invoice" — reached from the lease's Invoices tab,
     * which until 2026-08-29 offered no action at all and left the operator to leave the lease,
     * open the Payments resource and find the same document by number. Filling the tenant without
     * the allocation would be a worse answer than not filling anything: `suggestAllocations()`
     * spreads oldest-first, so a receipt raised to settle one invoice would quietly land on
     * another.
     *
     * **The id is re-checked against the reader's own scoped query, not trusted.** It arrives in a
     * query string, so a hand-typed one could name a tenant in a mall this user cannot see. The
     * EntitySelect would refuse it at validation anyway — Filament resolves a Select's value by
     * asking for its LABEL through the scoped query — but prefilling a value the form will later
     * reject presents as the page being broken rather than as a refusal, so it is dropped here.
     * The invoice is checked the same way and through the SAME scoped query the allocation
     * repeater's own picker uses, so the two can never disagree about what is reachable.
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $tenantId = (int) request()->query('tenant', 0);
        $invoiceId = (int) request()->query('invoice', 0);

        $state = [];

        // A named invoice carries its own tenant, so it wins: the two cannot disagree, and a
        // hand-edited pair naming an invoice that is not that tenant's would otherwise prefill a
        // receipt the over-allocation guard has to refuse later.
        $invoice = $invoiceId > 0
            ? InvoiceResource::getEloquentQuery()->whereKey($invoiceId)->first()
            : null;

        if ($invoice !== null) {
            $state['tenant_id'] = $invoice->tenant_id;
            $state['amount'] = round((float) $invoice->balance, 2);
            $state['allocations'] = [[
                'invoice_id' => $invoice->getKey(),
                'allocated_amount' => round((float) $invoice->balance, 2),
            ]];
        } elseif ($tenantId > 0 && TenantResource::getEloquentQuery()->whereKey($tenantId)->exists()) {
            $state['tenant_id'] = $tenantId;
        }

        $this->form->fill($state ?: null);

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
            $this->halt(shouldRollbackDatabaseTransaction: true);
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

            $this->halt(shouldRollbackDatabaseTransaction: true);
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

            $this->halt(shouldRollbackDatabaseTransaction: true);
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
            // NOT caught. A refusal here unwinds Filament's transaction (see
            // `$hasDatabaseTransactions` above), so the receipt is never committed, and the
            // `DomainException` reaches `bootstrap/app.php` — which words it for the operator as a
            // message and a redirect back, exactly as every other refusal in this app is worded.
            // Catching it to send a nicer title cost more than it bought: the first attempt at this
            // fix did, and had to swallow the exception to stay on the form, which poisoned the
            // Livewire component.
            //
            // Reachable in ONE request, no concurrency: the per-row cap in `PaymentForm` bounds each
            // allocation row independently while this hook SUMS rows against the same invoice, so
            // 700 + 600 on a 1,000 invoice passes every form gate and is refused here.
            $payment->assertInvoicesShareTenant(array_keys($sync));
            $payment->invoices()->sync($sync);
            $payment->recomputeAllocatedInvoices();
            $payment->assertInvoicesNotOverAllocated(array_keys($sync));

            // AFTER THE COMMIT, not inside it. `assertInvoicesNotOverAllocated()` holds
            // `lockForUpdate()` on the invoice and on four settlement tables, and
            // `PaymentReceivedNotification` is not `ShouldQueue` — its `mail` channel sends
            // synchronously, per portal user. Sent inside the transaction, every other capture,
            // credit-note application, deposit netting or write-off against that invoice queues
            // behind an SMTP round-trip and surfaces as a lock-wait timeout on an unrelated screen.
            // Previously the inner transaction had already committed by this point; under the outer
            // one it is only a SAVEPOINT, and releasing a savepoint does not release row locks.
            DB::afterCommit(fn () => $payment->notifyReceiptOnce());
        }
    }
}
