<?php

namespace App\Services\OwnerAccounting;

use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\OwnerStatement;
use App\Models\OwnerStatementRun;
use App\Services\Accounting\LedgerReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generate (or regenerate) a DRAFT owner-statement run for one property + accounting period:
 * the property's net (income − expenses, accrual, GL-derived) becomes the owner's statement.
 *
 * v1 (operator's model): one owner per mall who gets 100% of the net — no management fee, no
 * co-owner split. The full net → the current owner. If a mall somehow has >1 current owner the
 * net is split normalized by `ownership_percentage` so it ALWAYS sums to the full net (defensive;
 * the ownership-% split is otherwise a deferred future slice). No GL posting here — a draft is
 * disposable; the accrual is posted at finalise (slice 5).
 */
class GenerateOwnerStatementRunService
{
    public function __construct(private LedgerReportService $reports) {}

    public function generate(
        Asset $asset,
        AccountingPeriod $period,
        string $basis = OwnerStatementRun::BASIS_ACCRUAL,
    ): OwnerStatementRun {
        $periodStart = Carbon::parse($period->starts_on)->startOfDay();
        $periodEnd = Carbon::parse($period->ends_on)->startOfDay();

        return DB::transaction(function () use ($asset, $period, $basis, $periodStart, $periodEnd) {
            // Serialize concurrent generates of the same (asset, period). Look at the LATEST
            // version: a finalised run is corrected via Revise (refuse); a draft is regenerated
            // in place; a superseded latest means Revise just retired it, so start a fresh version.
            $latest = OwnerStatementRun::where('asset_id', $asset->id)
                ->where('accounting_period_id', $period->id)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            if ($latest && $latest->isFinalised()) {
                throw new \DomainException(
                    'A finalised statement already exists for this property and period — revise it instead of regenerating.'
                );
            }

            $run = ($latest && $latest->isDraft())
                ? $latest
                : new OwnerStatementRun([
                    'reference' => OwnerStatementRun::generateReference(),
                    'asset_id' => $asset->id,
                    'accounting_period_id' => $period->id,
                    'version' => $latest ? $latest->version + 1 : 1,
                    'supersedes_id' => $latest?->id,
                ]);

            // The property P&L for the period (accrual, GL-derived, closing-entry-excluded).
            $pl = $this->reports->incomeStatement([$asset->id], $periodStart, $periodEnd);
            $totalRevenue = round((float) $pl['total_revenue'], 2);
            $totalExpense = round((float) $pl['total_expense'], 2);
            $net = round((float) $pl['net_profit'], 2);

            // Snapshot the PER-ACCOUNT breakdown, not just the totals — a statement that only says
            // "revenue 500k, expense 200k" is not one an owner can read. Frozen alongside the totals
            // (recompute-then-freeze), so the detail can never drift from the net it sums to.
            $breakdown = [
                'revenue' => collect($pl['revenue'])->map(fn ($r) => [
                    'code' => $r['code'], 'name_en' => $r['name_en'], 'name_ar' => $r['name_ar'],
                    'amount' => round((float) $r['amount'], 2),
                ])->values()->all(),
                'expense' => collect($pl['expense'])->map(fn ($r) => [
                    'code' => $r['code'], 'name_en' => $r['name_en'], 'name_ar' => $r['name_ar'],
                    'amount' => round((float) $r['amount'], 2),
                ])->values()->all(),
            ];

            $run ??= new OwnerStatementRun([
                'reference' => OwnerStatementRun::generateReference(),
                'asset_id' => $asset->id,
                'accounting_period_id' => $period->id,
                'version' => 1,
            ]);

            $run->fill([
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'posting_date' => $periodEnd->toDateString(),
                'basis' => $basis,
                'total_revenue' => $totalRevenue,
                'total_expense' => $totalExpense,
                'net_operating_income' => $net,
                'income_breakdown' => $breakdown,
                'net_distributable' => 0,
                'status' => OwnerStatementRun::STATUS_DRAFT,
            ]);
            $run->save();

            $distributed = $this->rebuildStatements($run, $asset, $net, $totalRevenue, $totalExpense, $periodStart, $periodEnd);

            $run->net_distributable = $distributed;
            $run->save();

            return $run->load('statements');
        });
    }

    /**
     * Force-rebuild the run's child statements from the current owners (a draft is disposable,
     * so a hard rebuild sidesteps the soft-delete vs (run, user) unique clash). Returns the total
     * distributed (= net when there is at least one current owner; 0 when there is none).
     */
    private function rebuildStatements(
        OwnerStatementRun $run,
        Asset $asset,
        float $net,
        float $totalRevenue,
        float $totalExpense,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): float {
        $run->statements()->forceDelete();

        // Current owners = those whose ownership tenure overlaps the period (normally exactly one).
        $owners = $asset->owners()->get()->filter(function ($owner) use ($periodStart, $periodEnd) {
            /** @var \App\Models\AssetOwner $p */
            $p = $owner->pivot;
            $start = $p->started_at ?? $periodStart;
            $end = $p->ended_at ?? $periodEnd;

            return $start->lte($periodEnd) && $end->gte($periodStart);
        })->values();

        if ($owners->isEmpty()) {
            return 0.0; // no current owner → nothing to distribute (the net stays in retained earnings)
        }

        // Normalize by ownership_percentage so the shares ALWAYS sum to the full net (one owner
        // → 100%). A degenerate all-zero-percentage config falls back to an equal split.
        $totalPct = (float) $owners->sum(fn ($o) => (float) $o->pivot->ownership_percentage);
        $equalSplit = $totalPct <= 0.0;

        $weights = [];
        $shares = [];
        foreach ($owners as $owner) {
            $pct = (float) $owner->pivot->ownership_percentage;
            $weight = $equalSplit ? (1.0 / $owners->count()) : ($pct / $totalPct);
            $weights[$owner->id] = $weight;
            $shares[$owner->id] = round($net * $weight, 2);
        }

        // Penny reconciliation: put the rounding residual on the largest share so Σ shares == net
        // exactly — all the property money reaches the owner(s).
        $residual = round($net - array_sum($shares), 2);
        if (abs($residual) >= 0.01) {
            $largest = array_key_first($shares);
            foreach ($shares as $uid => $sh) {
                if ($sh > $shares[$largest]) {
                    $largest = $uid;
                }
            }
            $shares[$largest] = round($shares[$largest] + $residual, 2);
        }

        foreach ($owners as $owner) {
            /** @var \App\Models\AssetOwner $p */
            $p = $owner->pivot;
            $weight = $weights[$owner->id];

            $run->statements()->create([
                'reference' => OwnerStatement::generateReference(),
                'asset_id' => $asset->id,
                'user_id' => $owner->id,
                'ownership_percentage' => (float) $p->ownership_percentage,
                'tenure_from' => $p->started_at?->toDateString(),
                'tenure_to' => $p->ended_at?->toDateString(),
                'weight' => round($weight, 6),
                'owner_share' => $shares[$owner->id],
                'share_revenue' => round($totalRevenue * $weight, 2),
                'share_expense' => round($totalExpense * $weight, 2),
                'status' => OwnerStatement::STATUS_DRAFT,
            ]);
        }

        return round(array_sum($shares), 2);
    }
}
