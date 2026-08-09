<?php

namespace App\Services;

use App\Models\CamExpensePool;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Derive a recovery pool's two totals from the records that already hold them (stories RC-01/RC-05).
 *
 * **What was wrong.** Both numbers on `cam_expense_pools` were typed by a human.
 * `total_actual_expense` was re-keyed from a spreadsheet built off the vendor bills, so no tenant
 * charge could drill through to the invoices behind it and a single transcription slip re-billed
 * the whole mall. `total_estimated_collected` was one portfolio figure sliced pro-rata, which means
 * `estimated_paid` on an allocation was never what that tenant actually paid — it was their share
 * of a number somebody kept roughly equal to what had been billed.
 *
 * **It WRITES the totals rather than exposing a live query, deliberately.** A journal entry posted
 * late — a vendor bill that arrives in March for December — must not silently restate allocations
 * that have already been billed to tenants. Syncing is an act with a date on it
 * (`expense_synced_at`), and a reconciled or closed pool refuses it outright, which is the same
 * discipline as the frozen-share re-run guard.
 *
 * **Both bases default to `stated`** on every pool that already exists, so nothing is restated.
 */
class SyncCamPoolFromLedgerService
{
    /**
     * @return array{expense: ?float, estimate: ?float}  the totals written, null where the basis is `stated`
     */
    public function sync(CamExpensePool $pool): array
    {
        if (in_array($pool->status, ['reconciled', 'closed'], true)) {
            throw new InvalidArgumentException(
                "Pool #{$pool->id} is '{$pool->status}'; its totals are frozen. Re-open the year to re-source them."
            );
        }

        $expense = $pool->expense_basis === CamExpensePool::BASIS_LEDGER
            ? $this->actualFromLedger($pool)
            : null;

        $estimate = $pool->estimate_basis === CamExpensePool::BASIS_BILLED
            ? $this->estimateFromInvoices($pool)
            : null;

        if ($expense === null && $estimate === null) {
            return ['expense' => null, 'estimate' => null];
        }

        DB::transaction(function () use ($pool, $expense, $estimate): void {
            $pool->forceFill(array_filter([
                'total_actual_expense' => $expense,
                'total_estimated_collected' => $estimate,
                'expense_synced_at' => now(),
            ], fn ($v) => $v !== null))->save();
        });

        return ['expense' => $expense, 'estimate' => $estimate];
    }

    /**
     * The year's posted expense on the pool's chosen accounts, for the pool's property.
     *
     * Debits less credits, so a credit note from a vendor or a correcting reversal reduces the pool
     * exactly as it reduces the ledger — a debits-only sum would recover money from tenants that
     * the landlord had already been refunded.
     *
     * Only POSTED entries. A draft journal is not an expense yet, and a voided one never was.
     */
    public function actualFromLedger(CamExpensePool $pool): float
    {
        $accountIds = $pool->ledgerAccounts()->pluck('ledger_accounts.id');

        if ($accountIds->isEmpty()) {
            return 0.0;
        }

        $start = CarbonImmutable::create((int) $pool->period_year, 1, 1)->toDateString();
        $end = CarbonImmutable::create((int) $pool->period_year, 12, 31)->toDateString();

        $net = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.ledger_account_id', $accountIds)
            ->where('journal_entries.status', 'posted')
            // The pool belongs to ONE property, and so must its costs. A line stamped with another
            // asset — or with none — is not this mall's service charge, and recovering it here
            // would bill this mall's tenants for the mall next door.
            ->where('journal_lines.asset_id', $pool->asset_id)
            ->whereDate('journal_entries.entry_date', '>=', $start)
            ->whereDate('journal_entries.entry_date', '<=', $end)
            ->selectRaw('sum(journal_lines.debit) - sum(journal_lines.credit) as net')
            ->value('net');

        return round((float) $net, 2);
    }

    /**
     * The variable fraction of a ledger-sourced pool (story RC-04).
     *
     * Posted spend on the accounts marked `variable` over posted spend on all of them, so the split
     * follows the money rather than a count of accounts — one big variable account and nine tiny
     * fixed ones is a mostly-variable pool.
     *
     * An account with no nature reads as FIXED (the pivot default), which is the conservative
     * reading for this purpose: it grosses nothing and charges tenants nothing extra.
     */
    public function variableShareFromLedger(CamExpensePool $pool): float
    {
        $variableIds = $pool->ledgerAccounts
            ->filter(fn ($a) => $a->getAttribute('pivot')?->getAttribute('cost_nature') === 'variable')
            ->pluck('id');

        if ($variableIds->isEmpty()) {
            return 0.0;
        }

        $total = $this->actualFromLedger($pool);

        if ($total <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $this->netForAccounts($pool, $variableIds) / $total));
    }

    /**
     * Posted debits less credits on a subset of the pool's accounts, for its property and year.
     *
     * One definition, three callers (the pool total, the variable split, the controllable split) —
     * three copies of this query would eventually disagree about what "posted" or "this property"
     * means, and the splits would stop summing to the total they divide.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $accountIds
     */
    private function netForAccounts(CamExpensePool $pool, $accountIds): float
    {
        $start = CarbonImmutable::create((int) $pool->period_year, 1, 1)->toDateString();
        $end = CarbonImmutable::create((int) $pool->period_year, 12, 31)->toDateString();

        return (float) JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.ledger_account_id', $accountIds)
            ->where('journal_entries.status', 'posted')
            ->where('journal_lines.asset_id', $pool->asset_id)
            ->whereDate('journal_entries.entry_date', '>=', $start)
            ->whereDate('journal_entries.entry_date', '<=', $end)
            ->selectRaw('sum(journal_lines.debit) - sum(journal_lines.credit) as net')
            ->value('net');
    }

    /**
     * The controllable fraction of a ledger-sourced pool (story RC-07).
     *
     * Posted spend on the accounts flagged controllable over posted spend on all of them. The pivot
     * defaults to TRUE, so a pool nobody has classified is fully controllable and a
     * controllable-scoped cap behaves exactly like the unscoped cap it replaces.
     */
    public function controllableShareFromLedger(CamExpensePool $pool): float
    {
        $ids = $pool->ledgerAccounts
            ->filter(fn ($a) => (bool) ($a->getAttribute('pivot')?->getAttribute('is_controllable') ?? true))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0.0;
        }

        $total = $this->actualFromLedger($pool);

        if ($total <= 0) {
            return 1.0;
        }

        return max(0.0, min(1.0, $this->netForAccounts($pool, $ids) / $total));
    }

    /**
     * What the mall actually invoiced its tenants as service charge during the year.
     *
     * This is the number the reconciliation should compare against, and it was never available:
     * the pool held one hand-typed figure that a human kept roughly equal to the billing. Cancelled
     * and written-off invoices are excluded — nobody is being asked for that money.
     *
     * Scoped to the pool's property through the lease's unit, the same chain
     * `generateAllocations()` uses to pick participants.
     */
    public function estimateFromInvoices(CamExpensePool $pool): float
    {
        $start = CarbonImmutable::create((int) $pool->period_year, 1, 1)->toDateString();
        $end = CarbonImmutable::create((int) $pool->period_year, 12, 31)->toDateString();

        return round((float) $this->billedServiceChargeQuery($pool, $start, $end)->sum('invoice_items.amount'), 2);
    }

    /**
     * The same figure for ONE lease — what this tenant was actually billed in estimates.
     *
     * Used by the reconciliation when `estimate_basis = billed`, which is what makes the estimate
     * reconciled and the estimate billed the same number by construction rather than by diligence.
     */
    public function estimateBilledFor(CamExpensePool $pool, Lease $lease): float
    {
        $start = CarbonImmutable::create((int) $pool->period_year, 1, 1)->toDateString();
        $end = CarbonImmutable::create((int) $pool->period_year, 12, 31)->toDateString();

        return round((float) $this->billedServiceChargeQuery($pool, $start, $end)
            ->where('invoices.lease_id', $lease->id)
            ->sum('invoice_items.amount'), 2);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<\App\Models\InvoiceItem> */
    private function billedServiceChargeQuery(CamExpensePool $pool, string $start, string $end)
    {
        return \App\Models\InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('leases', 'leases.id', '=', 'invoices.lease_id')
            ->join('units', 'units.id', '=', 'leases.unit_id')
            ->where('units.asset_id', $pool->asset_id)
            ->whereNotIn('invoices.status', ['cancelled', 'written_off'])
            ->whereIn('invoice_items.type', CamExpensePool::ESTIMATE_ITEM_TYPES)
            // Keyed on the period the invoice COVERS, not the day it was raised: a December invoice
            // issued on 2 January belongs to the year the tenant occupied, which is the year being
            // reconciled.
            ->whereDate('invoices.period_start', '>=', $start)
            ->whereDate('invoices.period_start', '<=', $end);
    }
}
