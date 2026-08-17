<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\TenantCreditApplication;
use App\Support\PostingDate;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Apply / reverse a tenant's on-account CREDIT against one of their invoices.
 *
 * Applying creates a TenantCreditApplication — its OWN accounting document, posted
 * Dr Unearned Revenue / Cr Accounts Receivable dated NOW (an open period). It does NOT touch the
 * original receipt: re-deriving that (immutable, possibly closed-period) payment entry was the
 * critical bug a first attempt hit — AR would drop while the GL refused to move. Posting a fresh
 * dated-now correction is what makes applying an OLD overpayment to a current invoice safe.
 *
 * Reversing soft-deletes the application (LedgerPoster::sync voids its entry → AR re-opens → the
 * credit returns to available). Lock-safe (tenant + invoice rows), capped at
 * min(available credit, invoice balance, requested), same-property (isolation).
 */
class ApplyTenantCreditService
{
    public function applyToInvoice(Invoice $invoice, ?float $requested = null): float
    {
        return DB::transaction(function () use ($invoice, $requested) {
            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->lockForUpdate()->find($invoice->id);
            if (! $invoice || $invoice->status === 'cancelled' || round((float) $invoice->balance, 2) <= 0) {
                return 0.0;
            }

            // Lock the tenant row so two concurrent applies (to different invoices) can't both draw
            // the same credit — the second waits, then re-reads the now-reduced available balance.
            $tenant = $invoice->tenant()->lockForUpdate()->first();
            if (! $tenant instanceof Tenant) {
                return 0.0;
            }

            $assetId = $invoice->lease?->unit?->asset_id;
            if ($assetId === null) {
                // An invoice with no resolvable property must never draw from — or stamp — consolidated
                // credit: a null scope silently widens to ALL properties (the documented leak). Refuse.
                throw new DomainException(__('admin.payment.no_credit_to_apply'));
            }

            // Credit available for THIS property, and — as a cross-property backstop — the tenant's TOTAL.
            // A single receipt split across two malls reports its surplus under BOTH properties' scopes;
            // without the global cap the same 5,000 could be drawn once per property. Capping at the
            // unscoped balance too forbids ever drawing more than the tenant actually holds in aggregate
            // (Σ applications ≤ Σ surplus), while the per-property cap still blocks A-credit → B-invoice.
            $available = $tenant->creditBalance([$assetId]);
            $globalAvailable = $tenant->creditBalance(null);

            $cap = min(round((float) $invoice->balance, 2), round($available, 2), round($globalAvailable, 2));
            if ($requested !== null) {
                $cap = min($cap, round(max(0.0, $requested), 2));
            }
            $cap = round($cap, 2);

            if ($cap <= 0) {
                throw new DomainException(__('admin.payment.no_credit_to_apply'));
            }

            // The correction is dated TODAY. Refuse only if today's period is somehow closed (it never
            // is in normal operation) — mirrors every other operator-dated GL document.
            PostingDate::assertOpen(now(), __('admin.fields.payment_date'));

            TenantCreditApplication::create([
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'asset_id' => $assetId,
                'amount' => $cap,
                'entry_date' => now()->toDateString(),
                'created_by' => Auth::id(),
            ]);
            // The saved hook (LedgerRealtimeSync) posts Dr Unearned / Cr AR afterCommit.

            $invoice->recomputeTotals();

            return $cap;
        });
    }

    /**
     * Reverse every credit application on this invoice (soft-delete) — the sweep voids their GL
     * entries, the AR re-opens, and the credit returns to the tenant's available balance.
     */
    public function reverseForInvoice(Invoice $invoice): float
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()->lockForUpdate()->find($invoice->id);
            if (! $invoice) {
                return 0.0;
            }

            $reversed = 0.0;
            foreach (TenantCreditApplication::where('invoice_id', $invoice->id)->lockForUpdate()->get() as $app) {
                $reversed += (float) $app->amount;
                $app->delete(); // soft-delete → deleted hook syncs → GL entry voided
            }

            $invoice->recomputeTotals();

            return round($reversed, 2);
        });
    }
}
