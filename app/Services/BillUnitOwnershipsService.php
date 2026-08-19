<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\UnitOwnership;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The monthly صيانة run for unit owners — the assessment a buyer owes whether or not anybody trades
 * from his shop.
 *
 * ## Why this is not folded into `MonthlyBillingService`
 *
 * That service is 760 lines of lease law: fit-out grace, holdover, percentage rent, straight-line
 * rent, escalation ladders, the billing-cycle calendar. An ownership has none of it — it has a
 * tenure and a schedule. Generalising it would mean every one of those rules answering "not
 * applicable" at runtime, on the one path where a wrong answer bills the wrong person.
 *
 * **What is shared is what must never fork**, and both are shared here rather than reimplemented:
 *
 *  - `MonthlyBillingService::monthsCovered()` — the ONE definition of "how much of a period does this
 *    agreement actually run". A second copy would let a mid-month handover and a mid-month move-out
 *    prorate differently, by a day or two, forever.
 *  - `IssueInvoiceService` — the ONE place an AR document is born, so an assessment gets the same
 *    header, the same totals rule and the same `paid_amount`/`balance` seeding as every other
 *    invoice in the system.
 *
 * @see docs/modules/37-unit-owners.md
 */
class BillUnitOwnershipsService
{
    /**
     * Bill every handed-over ownership for the period.
     *
     * Idempotent and lock-safe, on the same terms as the lease run: one lock per period so a manual
     * run cannot race the scheduled one, and a per-ownership overlap probe inside the transaction so
     * a retry cannot double-bill.
     *
     * @return array{period:string, considered:int, created:int, skipped:int, unconfigured:int, failed:int}
     */
    public function runForPeriod(?CarbonImmutable $period = null, ?int $assetId = null): array
    {
        $period = ($period ?? CarbonImmutable::now())->startOfMonth();

        // Deliberately a DIFFERENT lock key from the lease run. The two bill disjoint sets of
        // agreements and never touch the same invoice, so sharing a key would serialise them for no
        // reason and make a property-scoped assessment run wait on a portfolio-wide rent run.
        $result = Cache::lock('assessments:run:'.$period->format('Y-m'), 900)
            ->get(fn (): array => $this->bill($period, $assetId));

        if ($result === false) {
            OpsLog::warning('Assessment run skipped — one is already in progress', ['period' => $period->format('Y-m')]);

            return ['period' => $period->format('Y-m'), 'considered' => 0, 'created' => 0, 'skipped' => 0, 'unconfigured' => 0, 'failed' => 0];
        }

        return $result;
    }

    /** @return array{period:string, considered:int, created:int, skipped:int, unconfigured:int, failed:int} */
    private function bill(CarbonImmutable $period, ?int $assetId): array
    {
        $periodStart = $period;
        $periodEnd = $period->endOfMonth();

        // `unconfigured` is counted SEPARATELY from `skipped`, and that distinction is the point.
        //
        // A skip means "nothing to bill this month" — a tenure that had not started, a resold unit,
        // a period already billed. All ordinary. An ownership that is HANDED OVER, in tenure, and
        // carries no assessment schedule at all is not ordinary: it is a unit nobody is billing,
        // and it will stay that way every month until someone notices. Both used to land in one
        // counter, so `{"considered":8,"created":6,"skipped":2,"failed":0}` read like success while
        // two owners went un-billed (pre-staging QA, F-01).
        $stats = ['period' => $period->format('Y-m'), 'considered' => 0, 'created' => 0, 'skipped' => 0, 'unconfigured' => 0, 'failed' => 0];

        UnitOwnership::query()
            ->when($assetId, fn ($q) => $q->where('asset_id', $assetId))
            ->with(['charges' => fn ($q) => $q->where('is_active', true)])
            ->chunkById(100, function ($ownerships) use (&$stats, $periodStart, $periodEnd) {
                foreach ($ownerships as $ownership) {
                    $stats['considered']++;

                    try {
                        $invoice = DB::transaction(fn (): ?Invoice => $this->billOne($ownership, $periodStart, $periodEnd));

                        if ($invoice) {
                            $stats['created']++;
                        } elseif ($this->isUnconfigured($ownership, $periodStart, $periodEnd)) {
                            $stats['unconfigured']++;
                            OpsLog::warning('Unit ownership is billable but has no assessment schedule — nothing will ever be billed for it', [
                                'unit_ownership_id' => $ownership->id,
                                'reference' => $ownership->reference,
                                'unit' => $ownership->unit?->code,
                                'period' => $periodStart->format('Y-m'),
                            ]);
                        } else {
                            $stats['skipped']++;
                        }
                    } catch (\Throwable $e) {
                        $stats['failed']++;
                        OpsLog::error('Assessment billing failed for a unit ownership', [
                            'unit_ownership_id' => $ownership->id,
                            'period' => $periodStart->format('Y-m'),
                            'exception' => $e,
                        ]);
                    }
                }
            });

        // A run that left owners un-billed is not an `info` event. Raising the level is what puts
        // it in front of somebody without needing a new screen.
        $stats['unconfigured'] > 0
            ? OpsLog::warning('Assessment run complete — some ownerships have no schedule and were not billed', $stats)
            : OpsLog::info('Assessment run complete', $stats);

        return $stats;
    }

    /**
     * One ownership, one period. Returns null when there is nothing to bill.
     *
     * Public so the admin action and a test can drive a single ownership on exactly the path the
     * sweep uses — a preview or a manual bill computed by a second implementation is one that can
     * disagree with the run.
     */
    public function billOne(UnitOwnership $ownership, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): ?Invoice
    {
        // Re-read under a row lock and re-check inside the transaction, so two concurrent runs
        // cannot both pass the already-billed probe and mint two assessments for one month.
        $locked = UnitOwnership::query()->lockForUpdate()->find($ownership->id);

        if (! $locked instanceof UnitOwnership || ! $locked->isBillableForPeriod($periodStart, $periodEnd)) {
            return null;
        }

        if ($this->alreadyBilled($locked, $periodStart, $periodEnd)) {
            return null;
        }

        $charges = $locked->charges()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Charge $c): bool => $this->appliesToPeriod($c, $periodStart, $periodEnd));

        if ($charges->isEmpty()) {
            return null;
        }

        // The share of the month this owner actually held the unit — the SAME rule the lease run
        // prorates by, called rather than copied. A handover on the 20th bills 11/30 of the month;
        // a resale on the 10th bills the seller 10/30 and the buyer the rest, because each
        // ownership's own tenure bounds the window.
        $windowStart = $locked->started_at !== null && $locked->started_at->gt($periodStart)
            ? CarbonImmutable::parse($locked->started_at)
            : $periodStart;

        $windowEnd = $locked->ended_at !== null && $locked->ended_at->lt($periodEnd)
            ? CarbonImmutable::parse($locked->ended_at)
            : $periodEnd;

        $multiplier = MonthlyBillingService::monthsCovered($periodStart, 1, $windowStart, $windowEnd);

        if ($multiplier <= 0) {
            return null;
        }

        // A co-owner pays his share of the unit's assessment, not the whole of it.
        $share = (float) ($locked->ownership_share_pct ?? 100) / 100;

        $items = $charges->map(function (Charge $charge) use ($multiplier, $share, $periodStart): array {
            $factor = $charge->frequency === 'monthly' ? $multiplier : 1.0;
            $amount = round((float) $charge->amount * $factor * $share, 2);

            // The rate for the DOCUMENT's date, resolved through the catalogue — never the rate that
            // happened to be stored when the schedule row was written. Same call the lease run makes.
            $vatRate = $charge->resolvedVatRate($periodStart);
            $vatAmount = round($amount * ($vatRate / 100), 2);

            $label = $charge->name.' - '.$periodStart->format('F Y');

            if ($factor < 1) {
                $label .= ' ('.round($factor * 100).'% pro-rated)';
            }

            return [
                'charge_id' => $charge->id,
                'description' => $label,
                'type' => $charge->type,
                'amount' => $amount,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total' => round($amount + $vatAmount, 2),
            ];
        })->values()->all();

        // Never bill a zero. A fully-abated or zero-amount schedule should produce no document at
        // all rather than a 0.00 invoice that ages, duns and posts an empty entry.
        if (array_sum(array_column($items, 'total')) <= 0) {
            return null;
        }

        $today = CarbonImmutable::now()->startOfDay();
        $dueBasis = $periodStart->greaterThan($today) ? $periodStart : $today;

        return app(IssueInvoiceService::class)->issue(
            agreement: $locked,
            items: $items,
            issueDate: $periodStart,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            // Anchored to the later of the period start and today, exactly as the lease run does, so
            // a back-filled assessment is not born overdue and immediately penalised.
            dueDate: $dueBasis->addDays($locked->paymentTermsDays()),
        );
    }

    /**
     * Billable, in tenure, and yet nothing to bill — the state that means MISCONFIGURED.
     *
     * Deliberately narrow. It asks only about the case a person has to fix: the ownership is
     * handed over, its tenure covers the period, it has not already been billed, and it has **no
     * active schedule row at all**. An ownership whose rows simply do not apply to this month
     * (a one-off that billed last year) is an ordinary skip and stays one.
     */
    private function isUnconfigured(UnitOwnership $ownership, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): bool
    {
        return $ownership->isBillableForPeriod($periodStart, $periodEnd)
            && ! $this->alreadyBilled($ownership, $periodStart, $periodEnd)
            && $ownership->charges()->where('is_active', true)->doesntExist();
    }

    /**
     * Has this ownership already been billed for a period overlapping this one?
     *
     * An OVERLAP check rather than an exact-period match, mirroring the lease run: a prorated final
     * month legitimately carries a shorter period, and an equality test would re-bill it.
     * Cancelled invoices do not count — a voided assessment must be re-billable.
     */
    private function alreadyBilled(UnitOwnership $ownership, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): bool
    {
        return Invoice::query()
            ->where('unit_ownership_id', $ownership->id)
            ->where('status', '!=', 'cancelled')
            ->whereDate('period_start', '<=', $periodEnd->toDateString())
            ->whereDate('period_end', '>=', $periodStart->toDateString())
            ->exists();
    }

    /** Is this schedule row in force during the period? */
    private function appliesToPeriod(Charge $charge, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): bool
    {
        if ($charge->start_date !== null && CarbonImmutable::parse($charge->start_date)->gt($periodEnd)) {
            return false;
        }

        if ($charge->end_date !== null && CarbonImmutable::parse($charge->end_date)->lt($periodStart)) {
            return false;
        }

        // A one-off bills once, in the month its start date falls in.
        if ($charge->frequency === 'one_time') {
            return $charge->start_date !== null
                && CarbonImmutable::parse($charge->start_date)->between($periodStart, $periodEnd);
        }

        return $charge->frequency === 'monthly';
    }
}
