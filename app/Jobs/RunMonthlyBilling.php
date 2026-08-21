<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\MonthlyBillingService;
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

    public function __construct(public ?string $period = null) {}

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

        // An EXPLICIT period is a manual dispatch — "bill March now" — and bills every property, as
        // it always did. Only the scheduled run asks whose day it is.
        if ($this->period !== null) {
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
        $today = CarbonImmutable::now();
        $lastDayOfMonth = (int) $today->endOfMonth()->day;

        $totals = [];
        $billed = [];

        foreach (Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->get() as $asset) {
            $day = (int) PropertySettings::get('billing.monthly_billing_day', $asset->id);

            // A property set to the 31st must still bill in February. Clamping to the month's last
            // day is the only reading that bills every property every month; leaving it unclamped
            // skips seven months of the year for a 31, and four for a 30.
            $due = min(max($day, 1), $lastDayOfMonth);

            if ($due !== (int) $today->day) {
                continue;
            }

            $stats = $service->runForPeriod($period, $asset->id);
            $billed[] = $asset->code;

            foreach ($stats as $key => $value) {
                if (is_numeric($value)) {
                    $totals[$key] = ($totals[$key] ?? 0) + $value;
                }
            }
        }

        $totals['properties_billed'] = $billed;

        Log::info('Monthly billing run complete', $totals);

        return $totals;
    }
}
