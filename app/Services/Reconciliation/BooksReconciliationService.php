<?php

namespace App\Services\Reconciliation;

use App\Models\Invoice;
use App\Models\Payment;

/**
 * Independently re-derives the accounts-receivable books from SOURCE records
 * (invoice line items, captured payment allocations, applied credit notes) and
 * compares them to the stored aggregate columns. Any mismatch is a discrepancy:
 * a stored value drifted from its source — a bug, a manual DB edit, or a write
 * that bypassed Invoice::recomputeTotals() (the single source of truth).
 *
 * READ-ONLY audit — never mutates data. Run it before a monthly close or a tax
 * filing to confirm "the books tie out", so the operator's accountant can trust
 * the numbers without reading code. See docs/BUSINESS-RULES.md.
 */
class BooksReconciliationService
{
    /** Money tolerance — one piastre. */
    private const EPS = 0.01;

    /**
     * @param  string|null  $month  Optional 'YYYY-MM' filter on issue_date; null = all invoices.
     * @return array{period:string, ok:bool, checks:array<int,array>, controlTotals:array}
     */
    public function run(?string $month = null): array
    {
        $query = Invoice::query()
            ->where('status', '!=', 'cancelled')
            ->with('items');

        if ($month) {
            [$year, $mon] = array_map('intval', explode('-', $month));
            $query->whereYear('issue_date', $year)->whereMonth('issue_date', $mon);
        }

        $invoices = $query->get();
        $checks = [];

        // 1. Composition: line items tie to the header, and subtotal + VAT = total.
        $d = [];
        foreach ($invoices as $inv) {
            if ($inv->items->isNotEmpty()) {
                $itemsSubtotal = round((float) $inv->items->sum('amount'), 2);
                $itemsVat = round((float) $inv->items->sum('vat_amount'), 2);
                if (abs($itemsSubtotal - (float) $inv->subtotal) > self::EPS) {
                    $d[] = $this->disc($inv, "line-item amounts {$itemsSubtotal} ≠ stored subtotal {$inv->subtotal}");
                }
                if (abs($itemsVat - (float) $inv->vat_amount) > self::EPS) {
                    $d[] = $this->disc($inv, "line-item VAT {$itemsVat} ≠ stored vat_amount {$inv->vat_amount}");
                }
            }
            if (abs((float) $inv->subtotal + (float) $inv->vat_amount - (float) $inv->total) > self::EPS) {
                $d[] = $this->disc($inv, "subtotal {$inv->subtotal} + VAT {$inv->vat_amount} ≠ total {$inv->total}");
            }
        }
        $checks[] = $this->check('invoice_composition', 'Invoice total = line-item subtotal + VAT', $d);

        // 2. Paid amount: captured payment allocations + applied credits = stored paid_amount.
        //    Mirrors Invoice::recomputeTotals() exactly; catches credit/payment drift.
        $d = [];
        foreach ($invoices as $inv) {
            $allocated = round((float) $inv->payments()
                ->where('payments.status', 'captured')
                ->sum('invoice_payment.allocated_amount'), 2);
            $derived = round($allocated + (float) $inv->credit_applied_amount, 2);
            if (abs($derived - (float) $inv->paid_amount) > self::EPS) {
                $d[] = $this->disc($inv, "derived paid {$derived} (captured {$allocated} + credit {$inv->credit_applied_amount}) ≠ stored paid_amount {$inv->paid_amount}");
            }
        }
        $checks[] = $this->check('paid_amount', 'Paid = captured payments + applied credits', $d);

        // 3. Balance: max(0, total − paid) = stored balance.
        $d = [];
        foreach ($invoices as $inv) {
            $derived = round(max(0, (float) $inv->total - (float) $inv->paid_amount), 2);
            if (abs($derived - (float) $inv->balance) > self::EPS) {
                $d[] = $this->disc($inv, "derived balance {$derived} ≠ stored balance {$inv->balance}");
            }
        }
        $checks[] = $this->check('balance', 'Balance = total − paid (floored at 0)', $d);

        // 4. No captured payment is allocated beyond its own amount.
        $d = [];
        foreach (Payment::query()->where('status', 'captured')->with('invoices')->get() as $p) {
            $alloc = round((float) $p->invoices->sum(fn ($i) => $i->pivot->allocated_amount), 2);
            if ($alloc - (float) $p->amount > self::EPS) {
                $d[] = ['ref' => $p->reference ?? "payment #{$p->id}", 'detail' => "allocated {$alloc} > captured amount {$p->amount}"];
            }
        }
        $checks[] = $this->check('payment_allocation', 'No payment allocated beyond its amount', $d);

        // 5. Marketing fund integrity: accrued + spent must match their derived
        //    sources (billed marketing items / recorded spends). Catches any drift.
        $d = [];
        foreach (\App\Models\MarketingBudget::query()->with('asset')->get() as $budget) {
            $accrued = round((float) \App\Models\InvoiceItem::query()
                ->where('invoice_items.type', 'marketing')
                ->whereHas('invoice', fn ($q) => $q->where('status', '!=', 'cancelled')
                    ->whereYear('issue_date', $budget->period_year)
                    ->whereHas('lease.unit', fn ($u) => $u->where('asset_id', $budget->asset_id)))
                ->sum('amount'), 2);
            $spent = round((float) $budget->spends()->sum('amount'), 2);
            $ref = ($budget->asset?->name ?? "asset {$budget->asset_id}")." {$budget->period_year}";

            if (abs($accrued - (float) $budget->accrued_amount) > self::EPS) {
                $d[] = ['ref' => $ref, 'detail' => "accrued stored {$budget->accrued_amount} ≠ billed marketing items {$accrued}"];
            }
            if (abs($spent - (float) $budget->spent_amount) > self::EPS) {
                $d[] = ['ref' => $ref, 'detail' => "spent stored {$budget->spent_amount} ≠ recorded spends {$spent}"];
            }
        }
        $checks[] = $this->check('marketing_budget', 'Marketing accrued = billed levies, spent = recorded spends', $d);

        // 6. CAM integrity: pro-rata allocations sum to the pool's actual expense
        //    (within rounding), and every BILLED allocation is backed by a charge
        //    (catches the "billed without/lost charge" + double-bill drift class).
        $d = [];
        foreach (\App\Models\CamExpensePool::query()->with('allocations')->get() as $pool) {
            if ($pool->allocations->isEmpty()) {
                continue;
            }
            $summed = round((float) $pool->allocations->sum('allocated_amount'), 2);
            $tolerance = 0.01 * max(1, $pool->allocations->count()); // pro-rata rounding slack
            if (abs($summed - (float) $pool->total_actual_expense) > $tolerance) {
                $d[] = ['ref' => "pool #{$pool->id} ({$pool->period_year})", 'detail' => "allocations sum {$summed} ≠ pool expense {$pool->total_actual_expense}"];
            }
            foreach ($pool->allocations->where('status', 'billed') as $alloc) {
                /** @var \App\Models\CamAllocation $alloc */
                // A billed allocation must be backed by EITHER a charge (positive
                // true-up) OR a credit note (negative true-up = credit owed).
                $hasCharge = $alloc->billed_charge_id && \App\Models\Charge::whereKey($alloc->billed_charge_id)->exists();
                $hasCredit = $alloc->billed_credit_note_id && \App\Models\CreditNote::whereKey($alloc->billed_credit_note_id)->exists();
                if (! $hasCharge && ! $hasCredit) {
                    $d[] = ['ref' => "pool #{$pool->id} alloc #{$alloc->id}", 'detail' => "billed but no backing charge/credit-note (charge={$alloc->billed_charge_id}, credit={$alloc->billed_credit_note_id})"];
                }
            }
        }
        $checks[] = $this->check('cam_allocations', 'CAM allocations tie to the pool + billed ones have a charge or credit note', $d);

        // Control totals — the figures an accountant reconciles against their own books.
        $controlTotals = [
            'invoiceCount'  => $invoices->count(),
            'invoiced'      => round((float) $invoices->sum('total'), 2),
            'collected'     => round((float) $invoices->sum('paid_amount'), 2),
            'creditApplied' => round((float) $invoices->sum('credit_applied_amount'), 2),
            // Outstanding AR = the canonical AR definition (open + owed), matching
            // outstandingBalance() / the AR-aging report — not every non-cancelled
            // invoice (which would fold in paid/credited/disputed/draft rows).
            'outstandingAR' => round((float) $invoices
                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                ->where('balance', '>', 0)
                ->sum('balance'), 2),
            'vatTotal'      => round((float) $invoices->sum('vat_amount'), 2),
        ];

        // Standing (unapplied) credit notes are a liability that nets against AR —
        // include them so net AR matches Tenant::outstandingBalance() (which nets
        // them) and the accountant doesn't read AR gross.
        $controlTotals['creditOutstanding'] = round((float) \App\Models\CreditNote::query()
            ->whereIn('status', ['issued', 'applied'])
            ->where('balance', '>', 0)
            ->sum('balance'), 2);
        $controlTotals['netAR'] = round($controlTotals['outstandingAR'] - $controlTotals['creditOutstanding'], 2);

        return [
            'period' => $month ?? 'all',
            'ok' => collect($checks)->every(fn ($c) => $c['passed']),
            'checks' => $checks,
            'controlTotals' => $controlTotals,
        ];
    }

    /** @param array<int,array{ref:string,detail:string}> $discrepancies */
    private function check(string $key, string $label, array $discrepancies): array
    {
        return ['key' => $key, 'label' => $label, 'passed' => count($discrepancies) === 0, 'discrepancies' => $discrepancies];
    }

    private function disc(Invoice $inv, string $detail): array
    {
        return ['ref' => $inv->number ?? "invoice #{$inv->id}", 'detail' => $detail];
    }
}
