<?php

namespace App\Services\Reconciliation;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\VendorBill;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;

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

    public function __construct(
        private LedgerReportService $reports,
        private AccountResolver $accounts,
    ) {}

    /**
     * @param  string|null  $month  Optional 'YYYY-MM' filter on issue_date; null = all invoices.
     * @param  bool  $deep  Also run the all-time GL-in-sync check (every posting document's entry
     *                      matches its current state) — slower (re-derives every doc), for pre-filing audits.
     * @return array{period:string, ok:bool, checks:array<int,array>, controlTotals:array}
     */
    public function run(?string $month = null, bool $deep = false): array
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
                ->whereIn('payments.status', \App\Models\Payment::RECEIVED_STATUSES)
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
        foreach (Payment::query()->whereIn('status', \App\Models\Payment::RECEIVED_STATUSES)->with('invoices')->get() as $p) {
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
        $pools = \App\Models\CamExpensePool::query()->with('allocations')->get();

        // Batch the per-billed-allocation backing lookups (charge exists / credit
        // note exists / charge reached a non-cancelled invoice) into three set
        // queries — instead of ~3 queries PER billed allocation (an N+1 that grows
        // with the number of billed CAM allocations across every pool).
        $billed = $pools->flatMap(fn ($pool) => $pool->allocations->where('status', 'billed'));
        // Both the cost true-up charge AND the admin-fee charge are backing documents to verify.
        $chargeIds = $billed->pluck('billed_charge_id')
            ->merge($billed->pluck('billed_admin_fee_charge_id'))
            ->filter()->unique()->values();
        $creditIds = $billed->pluck('billed_credit_note_id')->filter()->unique()->values();

        $existingCharges = $chargeIds->isEmpty()
            ? collect()
            : \App\Models\Charge::whereIn('id', $chargeIds)->pluck('id')->flip();
        $existingCredits = $creditIds->isEmpty()
            ? collect()
            : \App\Models\CreditNote::whereIn('id', $creditIds)->pluck('id')->flip();
        // charge_ids that reached a non-cancelled invoice (the lost-revenue guard).
        $reachedCharges = $chargeIds->isEmpty()
            ? collect()
            : \App\Models\InvoiceItem::query()
                ->whereIn('charge_id', $chargeIds)
                ->whereHas('invoice', fn ($q) => $q->where('status', '!=', 'cancelled'))
                ->distinct()
                ->pluck('charge_id')
                ->flip();

        foreach ($pools as $pool) {
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
                // A billed allocation must be backed by a charge (positive true-up),
                // a credit note (negative true-up), OR — when the true-up nets to zero
                // but an admin fee is owed — the admin-fee charge.
                $hasCharge = $alloc->billed_charge_id && $existingCharges->has($alloc->billed_charge_id);
                $hasCredit = $alloc->billed_credit_note_id && $existingCredits->has($alloc->billed_credit_note_id);
                $hasFee = $alloc->billed_admin_fee_charge_id && $existingCharges->has($alloc->billed_admin_fee_charge_id);
                // A settled allocation whose estimate exactly matched actual AND that carries
                // no fee legitimately has no financial document — no money moved.
                $settledNoMove = abs((float) $alloc->true_up_amount) < 0.005 && (float) $alloc->admin_fee_amount < 0.005;

                if (! $hasCharge && ! $hasCredit && ! $hasFee && ! $settledNoMove) {
                    $d[] = ['ref' => "pool #{$pool->id} alloc #{$alloc->id}", 'detail' => "billed but no backing charge/credit-note (charge={$alloc->billed_charge_id}, credit={$alloc->billed_credit_note_id}, fee={$alloc->billed_admin_fee_charge_id})"];
                } else {
                    // Any charge that backs a billed allocation must actually have REACHED a
                    // non-cancelled invoice (it's settled on a recovery invoice at bill() time).
                    // Catches the "billed but never invoiced" lost-revenue class for BOTH the
                    // cost true-up and the admin fee, instead of masking it.
                    if ($hasCharge && ! $reachedCharges->has($alloc->billed_charge_id)) {
                        $d[] = ['ref' => "pool #{$pool->id} alloc #{$alloc->id}", 'detail' => "billed true-up charge {$alloc->billed_charge_id} never reached a non-cancelled invoice (lost revenue)"];
                    }
                    if ($hasFee && ! $reachedCharges->has($alloc->billed_admin_fee_charge_id)) {
                        $d[] = ['ref' => "pool #{$pool->id} alloc #{$alloc->id}", 'detail' => "admin-fee charge {$alloc->billed_admin_fee_charge_id} never reached a non-cancelled invoice (lost revenue)"];
                    }
                }
            }
        }
        $checks[] = $this->check('cam_allocations', 'CAM allocations tie to the pool + billed ones have a charge or credit note', $d);

        // 7. GL tie-out: the general ledger's AR/AP control accounts must equal the
        //    source-derived receivables/payables. GL balances are CUMULATIVE, so this
        //    only makes sense all-time — skip it for a month-scoped run. Skipped
        //    entirely when the GL isn't configured/populated (nothing to tie out).
        if ($month === null) {
            $gl = $this->glTieOut();
            if (($gl['configured'] ?? false) === true) {
                $d = [];
                if (abs($gl['ar']['delta']) > self::EPS) {
                    $d[] = ['ref' => 'Accounts Receivable', 'detail' => "GL {$gl['ar']['gl']} ≠ source AR {$gl['ar']['expected']} (delta {$gl['ar']['delta']})"];
                }
                if (abs($gl['ap']['delta']) > self::EPS) {
                    $d[] = ['ref' => 'Accounts Payable', 'detail' => "GL {$gl['ap']['gl']} ≠ source AP {$gl['ap']['expected']} (delta {$gl['ap']['delta']})"];
                }
                $checks[] = $this->check('gl_tie_out', 'General ledger AR/AP ties to source documents', $d);

                // Deep, per-document check (opt-in via --deep): every posting document's entry
                // must equal its current re-derived state. Catches drift the AR/AP control-account
                // tie-out can't see — a mis-typed revenue split, wrong VAT, a stale cash/inventory/
                // deposit/payroll posting — by dry-running sync() over every source. Slower.
                if ($deep) {
                    $checks[] = $this->check('gl_in_sync', 'Every posting document\'s ledger entry matches its current state', $this->glDriftDiscrepancies());
                }
            }
        }

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

    /**
     * The single source of truth for the GL↔AR/AP tie-out: the general ledger's
     * control-account balances vs. the receivables/payables the source documents
     * imply (all-time / cumulative). Consumed by both the reconcile check above and
     * the `accounting:sync-ledger` printout, so the two can never disagree.
     *
     * @return array{configured:bool, ar?:array{gl:float,expected:float,delta:float}, ap?:array{gl:float,expected:float,delta:float}}
     */
    public function glTieOut(): array
    {
        // Nothing to tie out until the GL is both configured (roles mapped) AND
        // populated (something posted) — else skip rather than raise a false failure.
        if (! JournalEntry::where('status', 'posted')->exists()) {
            return ['configured' => false];
        }

        try {
            $arAccount = $this->accounts->account('accounts_receivable');
            $apAccount = $this->accounts->account('accounts_payable');
        } catch (\DomainException) {
            return ['configured' => false];
        }

        // Net AR = open invoice balances − standing (unapplied) credit notes; credited
        // invoices are excluded because their credit note reverses them on the GL side.
        // Round each sum before subtracting (matches the sync-command's original math).
        $glAr = round($this->reports->accountLedger($arAccount)['closing'], 2);
        // Exclude DRAFT invoices — the InvoiceJournalizer recognises revenue only at issue,
        // so a draft's balance is not on the GL; counting it here would raise a false AR delta.
        // 'written_off' joins the exclusions for the same reason as 'credited': the GL side has
        // already been relieved (Dr Bad Debt / Cr AR), so counting the invoice's untouched balance
        // here would raise a false AR delta on every written-off debt.
        $invoiceBalances = round((float) Invoice::whereNotIn('status', ['cancelled', 'credited', 'draft', 'written_off'])->sum('balance'), 2);
        $outstandingCredits = round((float) CreditNote::whereIn('status', ['issued', 'applied'])->sum('balance'), 2);
        $expectedAr = round($invoiceBalances - $outstandingCredits, 2);

        // AP = outstanding vendor-bill balances (excludes draft + cancelled).
        $glAp = round($this->reports->accountLedger($apAccount)['closing'], 2);
        $expectedAp = round((float) VendorBill::whereNotIn('status', ['cancelled', 'draft'])->sum('balance'), 2);

        return [
            'configured' => true,
            'ar' => ['gl' => $glAr, 'expected' => $expectedAr, 'delta' => round($glAr - $expectedAr, 2)],
            'ap' => ['gl' => $glAp, 'expected' => $expectedAp, 'delta' => round($glAp - $expectedAp, 2)],
        ];
    }

    /**
     * The GL-in-sync check body: dry-run sync() over every posting document and collect the ones
     * whose posted entry is out of step with their current state (would post / void / re-post).
     * Read-only via LedgerPoster::wouldChange. Capped so a mass drift can't flood the output.
     *
     * @return array<int,array{ref:string,detail:string}>
     */
    public function glDriftDiscrepancies(): array
    {
        $poster = app(LedgerPoster::class);
        $drifted = [];

        foreach (LedgerPoster::sources() as $class) {
            $query = $class::query();
            if (method_exists(new $class, 'trashed')) {
                $query->withTrashed();
            }

            // chunkById (not cursor) so a full-history audit on a large MySQL DB uses bounded
            // memory AND short-lived queries rather than one long-held streaming connection.
            $capped = false;
            $query->chunkById(500, function ($models) use ($poster, &$drifted, &$capped, $class) {
                foreach ($models as $model) {
                    if ($poster->wouldChange($model)) {
                        $drifted[] = [
                            'ref' => class_basename($class).' #'.$model->getKey(),
                            'detail' => 'ledger entry is out of sync with the document — run accounting:sync-ledger',
                        ];
                        if (count($drifted) >= 100) { // cap — the count is enough to fail the check
                            $capped = true;

                            return false; // stop chunking this source
                        }
                    }
                }
            });

            if ($capped) {
                return $drifted; // stop scanning further sources too
            }
        }

        return $drifted;
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
