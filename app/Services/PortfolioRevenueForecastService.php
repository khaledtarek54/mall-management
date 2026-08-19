<?php

namespace App\Services;

use App\Models\Lease;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What will the portfolio bill, month by month, from here to the end of the window?
 *
 * ## The benchmark, and the half of it this deliberately does not attempt
 *
 * Voyager's Forecast Manager is *(cited,
 * `docs/benchmarks/yardi/01-yardi-lease-administration.md` §334)* a "lease-by-lease revenue
 * projection **including speculative renewals and re-lets**", and §205 notes the forecast is
 * computable the day a lease is signed — which is true here because `ChargeScheduleService` writes
 * the whole ladder at signing.
 *
 * **The contracted half is built. The speculative half is not, and that is a decision.** Projecting
 * a renewal that has not been agreed, or a re-let of a unit nobody has taken, requires a renewal
 * probability and a market rent — two numbers this system does not hold and would have to invent.
 * An invented number in a revenue forecast is worse than a missing one: it is indistinguishable
 * from contracted income on the same chart, and it is the figure an owner would be shown. What is
 * here is **income the operator can point at a signed contract for**, and the screen says so.
 *
 * ## It computes nothing itself
 *
 * Every month of every lease comes from `LeaseBillingForecastService`, which in turn comes from
 * `MonthlyBillingService::planInvoiceForLease()` — the method the real run persists. This service
 * only adds up. A portfolio forecast with its own arithmetic would disagree with the invoices it
 * predicts, and would do so first on exactly the cases that matter: a proration edge, a cycle
 * boundary, an escalation step.
 *
 * That inheritance is also why an already-invoiced month reports what it ACTUALLY billed rather
 * than what it would bill today — the per-lease service makes that distinction, and summing it
 * keeps it.
 *
 * ## Cost
 *
 * One `planInvoiceForLease()` per lease per period: ~9 ms per lease over a 24-month horizon on the
 * demo portfolio, so ~0.3 s for 34 leases and a few seconds for several hundred. Acceptable for an
 * on-demand report and deliberately not cached — a cached forecast that silently predates a rent
 * change is the failure this is meant to prevent. If it ever needs to be faster, the answer is to
 * narrow the horizon, not to keep a stale copy.
 */
class PortfolioRevenueForecastService
{
    /**
     * The furthest ahead this will look, whatever it is asked for.
     *
     * The page offers 6, 12, 24 and 36 — but `horizon` is a public Livewire property, and Livewire
     * takes what the payload says, not what the `Select` rendered. Without a clamp a crafted
     * request asking for 600 months makes this plan an invoice per lease per month six hundred
     * times over; measured at 615 ms on a 34-lease demo, and linear in both leases and months on a
     * real portfolio. Nothing is leaked by it — it is simply work nobody asked for, which is the
     * cheapest kind of denial of service to hand someone.
     *
     * 60 months is five years, comfortably past the longest horizon the page offers and past any
     * commercial term this operator writes.
     */
    public const MAX_HORIZON_MONTHS = 60;

    public function __construct(private readonly LeaseBillingForecastService $perLease) {}

    /**
     * @param  int|null  $assetId  a property, or null for every property the user may see
     * @return array{
     *     months: list<array{period: string, total: float, by_type: array<string, float>, leases: int, actual: bool}>,
     *     by_type: array<string, float>,
     *     total: float,
     *     leases: int,
     *     from: string,
     *     to: string,
     * }
     */
    public function forecast(
        ?int $assetId = null,
        ?CarbonImmutable $from = null,
        int $horizonMonths = LeaseBillingForecastService::HORIZON_MONTHS,
    ): array {
        $from = ($from ?? CarbonImmutable::now())->startOfMonth();

        // Clamped, not trusted — see MAX_HORIZON_MONTHS.
        $horizonMonths = max(1, min($horizonMonths, self::MAX_HORIZON_MONTHS));
        $to = $from->addMonths($horizonMonths - 1)->endOfMonth();

        $leases = $this->leases($assetId);

        /** @var array<string, array{total: float, by_type: array<string, float>, leases: int, actual: bool}> $months */
        $months = [];
        $byType = [];
        $total = 0.0;

        foreach ($leases as $lease) {
            $forecast = $this->perLease->forecast($lease, $from, $horizonMonths);

            foreach ($forecast['rows'] as $row) {
                // A period the lease does not bill — fit-out, a gap in the schedule, a lease that
                // has ended inside the window. `billable` is the per-lease service's own answer and
                // carries a `reason`; summing an unbillable period would forecast income from a
                // month nobody is charged for.
                if (! ($row['billable'] ?? false)) {
                    continue;
                }

                $period = CarbonImmutable::parse($row['period_start'])->format('Y-m');

                $months[$period] ??= ['total' => 0.0, 'by_type' => [], 'leases' => 0, 'actual' => true];

                // NET of tax. A revenue forecast is what the business earns; the VAT on top is
                // collected for the state and is not income, so including it would overstate every
                // figure on the page by the standard rate.
                $net = (float) ($row['subtotal'] ?? 0);

                $months[$period]['total'] += $net;
                $months[$period]['leases']++;
                $total += $net;

                // A month is only "actual" if EVERY lease contributing to it has already been
                // invoiced. One un-billed lease makes the month a projection, and labelling a mixed
                // month as actual is how a forecast gets read as a fact.
                if (($row['invoice_id'] ?? null) === null) {
                    $months[$period]['actual'] = false;
                }

                foreach ($row['items'] ?? [] as $item) {
                    $type = (string) ($item['type'] ?? 'other');
                    $amount = (float) ($item['amount'] ?? 0);

                    $months[$period]['by_type'][$type] = ($months[$period]['by_type'][$type] ?? 0) + $amount;
                    $byType[$type] = ($byType[$type] ?? 0) + $amount;
                }
            }
        }

        ksort($months);

        return [
            'months' => collect($months)
                ->map(fn (array $m, string $period): array => [
                    'period' => $period,
                    'total' => round($m['total'], 2),
                    'by_type' => array_map(fn (float $v): float => round($v, 2), $m['by_type']),
                    'leases' => $m['leases'],
                    'actual' => $m['actual'],
                ])
                ->values()
                ->all(),
            'by_type' => array_map(fn (float $v): float => round($v, 2), $byType),
            'total' => round($total, 2),
            'leases' => $leases->count(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    /**
     * The leases whose contracted income this forecast is made of.
     *
     * Active only. A terminated or expired lease bills nothing ahead of it, and a `pending_approval`
     * one is not contracted income yet — including it would put revenue on the chart that nobody
     * has signed for, which is the same objection that keeps speculative renewals out.
     *
     * Property scope goes through `TenantScope::reportAssetIds()`, the one clamp every other report
     * uses, so this cannot show a property the operator is not entitled to see.
     *
     * @return Collection<int, Lease>
     */
    private function leases(?int $assetId): Collection
    {
        $visible = TenantScope::reportAssetIds($assetId);

        return Lease::query()
            ->where('status', 'active')
            ->when(
                $visible !== null,
                fn ($q) => $q->whereHas('unit', fn ($u) => $u->whereIn('asset_id', $visible)),
            )
            ->with(['charges', 'unit'])
            ->get();
    }
}
