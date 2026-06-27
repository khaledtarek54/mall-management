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

        // Control totals — the figures an accountant reconciles against their own books.
        $controlTotals = [
            'invoiceCount'  => $invoices->count(),
            'invoiced'      => round((float) $invoices->sum('total'), 2),
            'collected'     => round((float) $invoices->sum('paid_amount'), 2),
            'creditApplied' => round((float) $invoices->sum('credit_applied_amount'), 2),
            'outstandingAR' => round((float) $invoices->sum('balance'), 2),
            'vatTotal'      => round((float) $invoices->sum('vat_amount'), 2),
        ];

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
