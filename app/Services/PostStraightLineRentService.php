<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\StraightLineRentAdjustment;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Write one month's straight-line rent adjustments (story RA-02).
 *
 * **A no-op until the accountant enables it.** `BillingSettings::straight_line_rent_enabled` ships
 * false; with it off this posts nothing at all, which is why the capability can ship before the
 * ruling. Nothing about invoices, VAT or ETA changes either way.
 *
 * **Idempotent by construction** — `unique(lease_id, period)` plus an `updateOrCreate` on a row that
 * has not been posted for. Re-running the month is safe, which matters because this runs on a
 * schedule and a scheduler that cannot be re-run after a failure is a scheduler nobody trusts.
 *
 * **Forward-only.** `reverseFrom()` exists for an amendment: it soft-deletes the adjustments from a
 * date onward so the next run re-derives them against the new terms, and it refuses to touch a month
 * whose accounting period has closed. Together those are what "recalculate forward, never restate"
 * means in practice.
 */
class PostStraightLineRentService
{
    public function __construct(private StraightLineRentService $straightLine) {}

    /**
     * @return array{period:string, enabled:bool, leases:int, posted:int, skipped:int}
     */
    public function postForMonth(?CarbonImmutable $month = null, ?int $assetId = null): array
    {
        $month = ($month ?? CarbonImmutable::now())->startOfMonth();

        $stats = [
            'period' => $month->format('Y-m'),
            'enabled' => $this->straightLine->enabled(),
            'leases' => 0,
            'posted' => 0,
            'skipped' => 0,
        ];

        if (! $stats['enabled']) {
            return $stats;
        }

        Lease::query()
            ->whereIn('status', ['active', 'terminated', 'expired', 'renewed'])
            ->whereDate('commencement_date', '<=', $month->endOfMonth()->toDateString())
            ->when($assetId, fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)))
            ->with(['charges', 'unit'])
            ->chunkById(100, function ($leases) use ($month, &$stats): void {
                foreach ($leases as $lease) {
                    $stats['leases']++;

                    $schedule = $this->straightLine->scheduleFor($lease);
                    $adjustment = $this->straightLine->adjustmentFor($lease, $month);

                    if ($schedule === null || $adjustment === null) {
                        $stats['skipped']++;

                        continue;
                    }

                    DB::transaction(function () use ($lease, $month, $schedule, $adjustment): void {
                        // Looked up with `whereDate` and `withTrashed`, NOT `updateOrCreate`, for
                        // two reasons that both end in a unique-constraint violation:
                        //
                        //   - `period` is a DATE cast, so the stored value carries a time component
                        //     on some drivers and an equality match on 'Y-m-d' silently misses it;
                        //   - a REVERSED month is soft-deleted but still occupies the unique index,
                        //     so re-deriving after `reverseFrom()` — the whole point of the
                        //     forward-only path — would collide with its own tombstone.
                        //
                        // Restoring the trashed row is also the correct accounting: the poster then
                        // re-posts the entry it had voided, rather than leaving an orphan.
                        $row = StraightLineRentAdjustment::withTrashed()
                            ->where('lease_id', $lease->id)
                            ->whereDate('period', $month->toDateString())
                            ->first() ?? new StraightLineRentAdjustment([
                                'lease_id' => $lease->id,
                                'period' => $month->toDateString(),
                            ]);

                        if ($row->trashed()) {
                            $row->restore();
                        }

                        $row->fill([
                            'asset_id' => $lease->unit?->asset_id,
                            'billed_amount' => $this->straightLine->billedFor($lease, $month),
                            'straight_line_amount' => $schedule['monthly'],
                            'adjustment_amount' => $adjustment,
                            // The LAST DAY of the month being recognised — a recognition entry
                            // belongs in the period it recognises, not on the day the job ran.
                            'entry_date' => $month->endOfMonth()->toDateString(),
                        ])->save();
                    });

                    $stats['posted']++;
                }
            });

        OpsLog::info('Straight-line rent run complete', $stats);

        return $stats;
    }

    /**
     * Drop the adjustments from a month onward so the next run re-derives them (forward-only).
     *
     * Used after an amendment changes the term or the ladder: the average moves, and every month
     * from the change onward must be recomputed against it. Months whose period has CLOSED are left
     * alone — restating a signed-off period is precisely what the standards and the posting-date
     * guards both forbid.
     *
     * @return int adjustments reversed
     */
    public function reverseFrom(Lease $lease, CarbonImmutable $from): int
    {
        $from = $from->startOfMonth();
        $reversed = 0;

        StraightLineRentAdjustment::query()
            ->where('lease_id', $lease->id)
            ->whereDate('period', '>=', $from->toDateString())
            ->get()
            ->each(function (StraightLineRentAdjustment $adjustment) use (&$reversed): void {
                if (\App\Support\PostingDate::isClosed($adjustment->entry_date)) {
                    return;
                }

                $adjustment->delete();
                $reversed++;
            });

        return $reversed;
    }
}
