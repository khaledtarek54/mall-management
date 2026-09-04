<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantCreditApplication;

/**
 * The three per-tenant money figures a LIST needs, computed once for the whole page.
 *
 * ## Why this exists
 *
 * `TenantsTable` renders three columns from `Tenant::isDelinquent()`, `outstandingBalance()` and
 * `creditBalance()`. Each is correct and each queries the database, so on a 25-row page they cost
 * about **125 queries** — measured 2026-09-01: the tenants list issued 181 queries and was the
 * second-slowest screen in the panel. The four settlement channels were being recomputed per row.
 *
 * ## The rule it must not break
 *
 * There is ONE definition of what a tenant owes, and it lives on the model. This class does not
 * restate it — it runs the SAME filters and the SAME `Invoice::collectableBalanceSql()` over a SET
 * of tenants instead of one, and for the credit balance it batches only the FETCH and reuses the
 * model's own per-payment arithmetic verbatim.
 *
 * That last point is deliberate. `creditBalance()` clamps **per payment** (`max(0, amount −
 * allocated)`), which is not `SUM(amount) − SUM(allocated)`; expressing it in SQL would need
 * `GREATEST`, which does not exist in SQLite and would be green in the test suite and fatal on the
 * real database — a trap this codebase has already been caught by. So the clamp stays in PHP,
 * written once.
 *
 * `TenantBalancesMatchThePerRowMethodsTest` asserts the two agree, because the only real hazard
 * here is drift: a batched figure that quietly disagrees with the record page beside it is worse
 * than a slow list.
 *
 * ## Bound `scoped`, never `singleton`
 *
 * The memo is per REQUEST. A queue worker outlives the request, and a stale arrears figure held
 * across jobs would be read as current — the same reasoning that governs `NavigationItemMemo`.
 */
final class TenantBalances
{
    /** @var array<string, array<int, array{outstanding: float, credit: float, delinquent: bool}>> */
    private array $memo = [];

    /**
     * @param  array<int, int>  $tenantIds
     * @param  array<int, int>|null  $assetIds
     * @return array<int, array{outstanding: float, credit: float, delinquent: bool}>
     */
    public function for(array $tenantIds, ?array $assetIds = null): array
    {
        $tenantIds = array_values(array_unique(array_map('intval', $tenantIds)));

        if ($tenantIds === []) {
            return [];
        }

        $key = md5(json_encode([$tenantIds, $assetIds]));

        return $this->memo[$key] ??= $this->compute($tenantIds, $assetIds);
    }

    /** The figures for ONE tenant, still batched if the page already asked for the set. */
    public function one(Tenant $tenant, ?array $assetIds = null): array
    {
        return $this->for([$tenant->getKey()], $assetIds)[$tenant->getKey()]
            ?? ['outstanding' => 0.0, 'credit' => 0.0, 'delinquent' => false];
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, int>|null  $assetIds
     * @return array<int, array{outstanding: float, credit: float, delinquent: bool}>
     */
    private function compute(array $ids, ?array $assetIds): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['outstanding' => 0.0, 'credit' => 0.0, 'delinquent' => false];
        }

        // --- outstanding: the SAME statuses and the SAME collectable SQL as the model ----------
        $invoiceBalances = Invoice::query()
            ->whereIn('tenant_id', $ids)
            ->stillOwed()
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->groupBy('tenant_id')
            // selectRaw + alias, NOT pluck(DB::raw(...)): pluck treats a raw expression as a
            // COLUMN NAME on the result row and reads `stdClass::$SUM(case when …)`, which is an
            // undefined-property fatal rather than a wrong number.
            ->selectRaw('tenant_id, SUM('.Invoice::collectableBalanceSql().') as aggregate')
            ->pluck('aggregate', 'tenant_id');

        $creditNoteBalances = CreditNote::query()
            ->whereIn('tenant_id', $ids)
            ->where('status', 'issued')
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->groupBy('tenant_id')
            ->selectRaw('tenant_id, SUM(balance) as aggregate')
            ->pluck('aggregate', 'tenant_id');

        foreach ($ids as $id) {
            $out[$id]['outstanding'] = round(
                (float) ($invoiceBalances[$id] ?? 0) - (float) ($creditNoteBalances[$id] ?? 0),
                2,
            );
        }

        // --- delinquent: the SAME predicate, asked of the set ---------------------------------
        $delinquent = Invoice::query()
            ->whereIn('tenant_id', $ids)
            ->where('due_date', '<', now())
            ->stillOwed()
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->distinct()
            ->pluck('tenant_id');

        foreach ($delinquent as $id) {
            $out[(int) $id]['delinquent'] = true;
        }

        // --- credit: batch the FETCH, reuse the model's per-payment clamp ----------------------
        $payments = Payment::query()
            ->whereIn('tenant_id', $ids)
            ->received()
            // The same one definition `Tenant::creditBalance()` reads — this WAS a second copy of
            // it, kept in step only by a comment saying so (SW-012).
            ->inProperties($assetIds)
            ->with('invoices')
            ->get();

        foreach ($payments as $payment) {
            $allocated = (float) $payment->invoices->sum(fn ($i) => (float) $i->pivot->allocated_amount);
            $out[(int) $payment->tenant_id]['credit'] += max(0.0, round((float) $payment->amount - $allocated, 2));
        }

        $applied = TenantCreditApplication::query()
            ->whereIn('tenant_id', $ids)
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->groupBy('tenant_id')
            ->selectRaw('tenant_id, SUM(amount) as aggregate')
            ->pluck('aggregate', 'tenant_id');

        foreach ($ids as $id) {
            $out[$id]['credit'] = round($out[$id]['credit'] - (float) ($applied[$id] ?? 0), 2);
        }

        return $out;
    }
}
