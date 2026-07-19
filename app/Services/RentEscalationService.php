<?php

namespace App\Services;

use App\Models\Lease;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Automatic contractual rent escalation. Leases carry `escalation_rate` / `escalation_type` /
 * `next_escalation_date`, but nothing applied them — so every anniversary increase was a manual
 * `LeaseRentChangeService` call, and a missed one leaked revenue (competitive gap analysis,
 * [docs/gap-analysis/competitors/01-lease-billing.md]). This sweep applies the increase through
 * `LeaseRentChangeService` (which keeps the base-rent Charge + marketing levy in lock-step) and
 * rolls `next_escalation_date` forward a year.
 *
 * Idempotent + lock-safe: each lease is row-locked and its due-ness re-checked inside the
 * transaction, and applying advances `next_escalation_date` past today so a re-run is a no-op.
 * One step per run — a multi-year backlog (a mis-set date) catches up over subsequent runs rather
 * than compounding many years in a single pass. CPI escalation is skipped (no index feed —
 * inventing a CPI number would be inventing data); only `fixed_percent` is applied.
 */
class RentEscalationService
{
    public function __construct(private LeaseRentChangeService $rentChange) {}

    /** @return array{considered:int, applied:int, skipped:int, failed:int} */
    public function runForToday(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now()->startOfDay();
        $stats = ['considered' => 0, 'applied' => 0, 'skipped' => 0, 'failed' => 0];

        $dueIds = Lease::query()
            ->where('status', 'active')
            ->whereIn('escalation_type', ['fixed_percent', 'cpi'])
            ->whereNotNull('next_escalation_date')
            ->whereDate('next_escalation_date', '<=', $today->toDateString())
            ->pluck('id');

        foreach ($dueIds as $id) {
            $stats['considered']++;
            try {
                $stats[$this->applyOne((int) $id, $today)]++;
            } catch (\Throwable $e) {
                // Per-row containment — one bad lease can't stop the sweep (mirrors the SLA scans).
                $stats['failed']++;
                OpsLog::error('rent_escalation.failed', ['lease_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return $stats;
    }

    /** @return 'applied'|'skipped' */
    private function applyOne(int $leaseId, CarbonImmutable $today): string
    {
        return DB::transaction(function () use ($leaseId, $today) {
            /** @var Lease|null $lease */
            $lease = Lease::whereKey($leaseId)->lockForUpdate()->first();

            // Re-check due-ness under the lock (idempotent + concurrency-safe).
            if (! $lease
                || $lease->status !== 'active'
                || $lease->next_escalation_date === null
                || $lease->next_escalation_date->gt($today)) {
                return 'skipped';
            }

            // CPI needs an external index feed we don't have — never invent the number.
            if ($lease->escalation_type !== 'fixed_percent') {
                return 'skipped';
            }

            $rate = (float) $lease->escalation_rate;
            $nextDate = $lease->next_escalation_date->copy()->addYear();

            if ($rate <= 0) {
                // Nothing to escalate; still roll the date so it isn't re-considered every day.
                $lease->forceFill(['next_escalation_date' => $nextDate])->save();

                return 'skipped';
            }

            $newRent = round((float) $lease->base_rent_monthly * (1 + $rate / 100), 2);

            $this->rentChange->apply($lease, [
                'base_rent_monthly' => $newRent,
                'reason' => "Automatic rent escalation +{$rate}%",
            ]);

            // Advance one year (the base_rent Charge + marketing levy were synced by apply()).
            $lease->forceFill(['next_escalation_date' => $nextDate])->save();

            return 'applied';
        });
    }
}
