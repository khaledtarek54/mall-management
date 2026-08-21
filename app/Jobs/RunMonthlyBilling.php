<?php

namespace App\Jobs;

use App\Services\MonthlyBillingService;
use App\Support\BillingDay;
use App\Support\PropertySettings;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunMonthlyBilling implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * @param  bool  $dueTodayOnly  the SCHEDULED sweep sets this; it bills only the properties whose
     *                              billing day is today. A manual run leaves it false and bills
     *                              every property, because somebody is asking for it now.
     */
    public function __construct(public ?string $period = null, public bool $dueTodayOnly = false) {}

    /**
     * Serialise billing runs per period so a manually-dispatched run can't race
     * the scheduled one and double-bill (the existence check is not yet behind a
     * DB unique constraint — see docs/modules/05-billing-invoices.md).
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('monthly-billing:'.($this->period ?? 'current')))->dontRelease()];
    }

    public function handle(MonthlyBillingService $service): array
    {
        $period = $this->period
            // !Y-m, not Y-m: with no day in the format, Carbon fills it from TODAY. On the 29th–31st that overflows a shorter month — "2026-02" parsed on the 29th becomes 1 March — so the period silently shifts by a month. The `!` resets every unspecified field, giving midnight on the 1st.
            ? CarbonImmutable::createFromFormat('!Y-m', $this->period)->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();

        // Keyed on an EXPLICIT flag, never on "was a period given".
        //
        // Inferring it from `$period === null` made `billing:run-monthly --queue` — the catch-up run
        // after a failed billing night — a silent no-op on twenty-nine days in thirty, while printing
        // "job dispatched" and logging "run complete". A person invoking the command is asking for it
        // NOW; only the scheduler asks whose day it is.
        if (! $this->dueTodayOnly) {
            $stats = $service->runForPeriod($period);
            Log::info('Monthly billing run complete', $stats);

            return $stats;
        }

        return $this->runPropertiesDueToday($service, $period);
    }

    /**
     * Bill the properties whose billing day is TODAY.
     *
     * The schedule fires daily and this decides, rather than the scheduler firing `->monthlyOn($day)`
     * — because there is one scheduler for the whole portfolio and `monthly_billing_day` became a
     * per-property override (M-5). Without this, an operator setting Mall B to the 25th would see it
     * saved and the run would still fire on the 1st: an override nothing consults, which
     * `PropertySettings`' own docblock calls worse than no override at all.
     *
     * @return array<string, mixed>
     */
    private function runPropertiesDueToday(MonthlyBillingService $service, CarbonImmutable $period): array
    {
        $totals = [];
        $billed = [];

        foreach (BillingDay::propertiesDueOn(CarbonImmutable::now()) as $assetId => $code) {
            $stats = $service->runForPeriod($period, $assetId);
            $billed[] = $code;

            foreach ($stats as $key => $value) {
                // Numeric counters accumulate; everything else (the period label, the failed lease
                // ids) is carried through rather than dropped — `is_numeric()` alone silently ate
                // both, so a run that failed leases logged no way to find them.
                if (is_numeric($value)) {
                    $totals[$key] = ($totals[$key] ?? 0) + $value;
                } elseif (is_array($value)) {
                    $totals[$key] = array_merge($totals[$key] ?? [], $value);
                } else {
                    $totals[$key] ??= $value;
                }
            }
        }

        $totals['properties_billed'] = $billed;

        Log::info('Monthly billing run complete', $totals);

        return $totals;
    }
}
