<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\TenantCreditApplication;
use App\Support\PostingDate;
use App\Support\ReversalReason;
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

            // The invoice's OWN column, never `lease?->unit?->asset`. `invoices.lease_id` is nullable
            // since module 37 and null BY CONSTRUCTION for a unit-owner assessment
            // (`UnitOwnership::invoiceLinkAttributes()` returns `lease_id => null`, and
            // `assertBelongsToExactlyOneAgreement()` enforces it) — so the chain answered null for every
            // owner assessment and this guard refused every one of them. Silently: the refusal is a
            // `DomainException`, and `Invoice::saved()` catches exactly that as "the ordinary case, most
            // invoices have no credit" without a log line. With `auto_apply_tenant_credit` shipping TRUE,
            // no unit owner's on-account credit has ever been drawn down, and the monthly assessment
            // re-billed them in full. `asset_id` is NOT NULL — `Invoice::creating` derives it and refuses
            // to save without it — so the guard below is now an inert backstop rather than the live path.
            $assetId = $invoice->asset_id;
            if ($assetId === null) {
                // A null scope silently widens to ALL properties (the documented leak). Refuse.
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
    public function reverseForInvoice(Invoice $invoice, ?string $reason = null): float
    {
        return DB::transaction(function () use ($invoice, $reason) {
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

            // Filed against the INVOICE, not the applications: the applications are soft-deleted by
            // the loop above and a trail row pointing at a deleted subject is one nobody will find.
            // The invoice is what the operator was looking at and what re-opened.
            ReversalReason::record($invoice, 'credit_reversed', $reason);

            return round($reversed, 2);
        });
    }
}
