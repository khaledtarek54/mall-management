<?php

namespace App\Services\Reports;

use App\Models\FacilityWorkOrder;
use App\Models\SlaPenalty;
use App\Models\Vendor;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How each vendor has actually performed — from what the system already recorded.
 *
 * Nothing here is new data. Every figure is a by-product of work the operator was doing anyway:
 * work orders acknowledged and completed, SLA targets missed, penalties applied, compliance
 * documents allowed to lapse. The gap this closes is that **none of it was ever brought together
 * per vendor**, so "who is actually any good" was answered from memory at renewal time.
 *
 * **Counts and times, never a single score.** A composite number would have to weight
 * responsiveness against cost against compliance, and that weighting is the operator's judgement,
 * not something to bury in a service — a vendor who is slow but cheap may be exactly right for
 * routine work. The report says what happened; the ranking stays with the person renewing.
 *
 * Read-only.
 */
class VendorScorecardService
{
    /**
     * @return Collection<int, array{
     *     vendor: Vendor, work_orders: int, completed: int, open: int,
     *     avg_response_hours: ?float, avg_resolution_hours: ?float,
     *     sla_breaches: int, penalties_applied: int, penalty_total: float,
     *     expired_documents: int, dispatchable: bool,
     * }>
     */
    public function for(CarbonImmutable $start, CarbonImmutable $end, ?int $assetId = null): Collection
    {
        // The third report taking `ReportFilters::from()`/`to()`, and the same rule — an inverted
        // window makes every `whereBetween` below match nothing, so every vendor scores zero jobs
        // and zero breaches, which reads as a clean record. See ReportPeriod::orderedSpan().
        [$start, $end] = ReportPeriod::orderedSpan($start, $end);
        $orders = FacilityWorkOrder::query()
            ->whereNotNull('vendor_id')
            ->when($assetId, fn ($q) => $q->where('asset_id', $assetId))
            ->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()])
            ->get()
            ->groupBy('vendor_id');

        $penalties = SlaPenalty::query()
            ->where('status', SlaPenalty::STATUS_APPLIED)
            ->when($assetId, fn ($q) => $q->where('asset_id', $assetId))
            ->whereBetween('applied_at', [$start->startOfDay(), $end->endOfDay()])
            ->get()
            ->groupBy('vendor_id');

        // Every vendor that did work OR was penalised in the window. A vendor with no activity is
        // absent rather than shown as a row of zeroes: "no jobs" is not a performance record, and
        // padding the report with them buries the vendors it is about.
        $vendorIds = $orders->keys()->merge($penalties->keys())->unique()->filter()->values();

        $vendors = Vendor::query()->whereIn('id', $vendorIds)->get()->keyBy('id');

        $rows = collect();

        foreach ($vendorIds as $vendorId) {
            $vendor = $vendors->get($vendorId);

            if (! $vendor instanceof Vendor) {
                continue;
            }

            /** @var Collection<int, FacilityWorkOrder> $vendorOrders */
            $vendorOrders = $orders->get($vendorId, collect());
            $vendorPenalties = $penalties->get($vendorId, collect());

            $completed = $vendorOrders->filter(fn (FacilityWorkOrder $o) => $o->completed_at !== null);

            $rows->push([
                'vendor' => $vendor,
                'work_orders' => $vendorOrders->count(),
                'completed' => $completed->count(),
                'open' => $vendorOrders->count() - $completed->count(),
                // Time to ACKNOWLEDGE — how long the vendor took to pick the job up. Null when
                // nothing was acknowledged, rather than 0, because "instant" and "never" are
                // opposite answers and averaging the second as zero flatters the vendor.
                'avg_response_hours' => self::averageHours($vendorOrders, 'created_at', 'acknowledged_at'),
                'avg_resolution_hours' => self::averageHours($vendorOrders, 'created_at', 'completed_at'),
                // Breached its target, whether or not anyone penalised it — the two are
                // different facts and a vendor is not owed the benefit of an un-chased breach.
                'sla_breaches' => $vendorOrders->filter(fn (FacilityWorkOrder $o) => self::breached($o))->count(),
                // **The provider who keeps coming back to bill twice** (ServiceChannel §4). A
                // vendor whose jobs are disproportionately repeats is either not fixing the fault
                // or being sent to a machine that needs replacing — and both are conversations the
                // renewal should have. Counted on the vendor's OWN jobs that are repeats, not on
                // every repeat at their sites: a contractor is answerable for returning to their
                // own work, not for a fault somebody else failed to fix first.
                'repeat_visits' => $vendorOrders->filter(fn (FacilityWorkOrder $o) => $o->isRepeatVisit())->count(),
                'penalties_applied' => $vendorPenalties->count(),
                'penalty_total' => round((float) $vendorPenalties->sum('amount'), 2),
                'expired_documents' => $vendor->documents()
                    ->whereNotNull('expires_on')
                    ->whereDate('expires_on', '<', now()->toDateString())
                    ->count(),
                // The compliance gate the dispatch path already enforces, surfaced here so the
                // renewal conversation includes it.
                'dispatchable' => $vendor->isDispatchable(),
            ]);
        }

        return $rows->sortByDesc('sla_breaches')->values();
    }

    /** Did this order miss its target? Unresolved past its target counts — it is still late. */
    private static function breached(FacilityWorkOrder $order): bool
    {
        if ($order->target_resolution_at === null) {
            return false;
        }

        $finished = $order->completed_at;

        return $finished !== null
            ? $finished->greaterThan($order->target_resolution_at)
            : now()->greaterThan($order->target_resolution_at);
    }

    /**
     * Mean hours between two stamps, over the orders that HAVE both.
     *
     * Null when none qualify. A vendor who never acknowledged anything must not average zero hours
     * and appear instant — the absence of a stamp is the finding, and it shows in the count columns
     * beside this one.
     *
     * @param  Collection<int, FacilityWorkOrder>  $orders
     */
    private static function averageHours(Collection $orders, string $from, string $to): ?float
    {
        $spans = $orders
            ->filter(fn (FacilityWorkOrder $o) => $o->{$from} !== null && $o->{$to} !== null)
            ->map(fn (FacilityWorkOrder $o) => $o->{$from}->diffInMinutes($o->{$to}) / 60);

        return $spans->isEmpty() ? null : round((float) $spans->avg(), 1);
    }
}
