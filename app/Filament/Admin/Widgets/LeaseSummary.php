<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\LeaseOption;
use App\Services\ChargeScheduleService;
use App\Services\MoveOutStatementService;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * **The tenancy at a glance — UX-01's Summary, the half of the lease hub that was missing.**
 *
 * Every tab on the lease already made a fact REACHABLE; none made the important ones VISIBLE
 * together. The story that asked for this page said why in one line: *"so that I stop hunting across
 * five resources."* Reachable and visible are not the same property, and the six numbers below were
 * spread over the charge schedule, the invoices tab, the deposits tab, the options panel and a
 * report.
 *
 * **It computes nothing of its own.** The rent in force comes from `ChargeScheduleService::pickInForce()`
 * — the same selection billing uses — the deposit from `MoveOutStatementService::depositHeld()`, the
 * AR from the invoices themselves. A summary that derived its own figures would be a second opinion
 * about them, and the first thing anyone would notice is that it disagreed with the tab underneath it.
 *
 * Page-scoped, not a dashboard panel: it is meaningless without a lease, and `DashboardLayout::NOT_ON_DASHBOARD`
 * records that deliberately — the registry exists because Filament auto-discovers everything in this
 * directory, and a widget nobody classified once published a property's whole receivables ledger to
 * every role on the panel.
 */
class LeaseSummary extends StatsOverviewWidget
{
    /** Injected by the resource page — Filament passes `record` to a record page's header widgets. */
    public ?Lease $record = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Auth::user()?->can('leases.view') ?? false;
    }

    protected function getColumns(): int
    {
        return 3;
    }

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        if (! $this->record instanceof Lease) {
            return [];
        }

        $lease = $this->record->loadMissing(['charges', 'options', 'unit']);
        $today = CarbonImmutable::now()->startOfDay();

        return [
            $this->rent($lease, $today),
            $this->premises($lease, $today),
            $this->term($lease, $today),
            $this->receivable($lease),
            $this->deposit($lease),
            $this->criticalDate($lease, $today),
        ];
    }

    /** What this lease bills today, and when that next changes — read from the schedule, not the column. */
    private function rent(Lease $lease, CarbonImmutable $today): Stat
    {
        $rentRows = $lease->charges->where('type', 'base_rent');

        // `pickInForce()` falls back to the latest active row when nothing covers the date — right
        // for a rent roll, where a pre-schedule lease with one open-ended row must not read as "no
        // rent". Wrong here: this card answers "what is billing TODAY", and a lease that has not
        // commenced is billing nothing. The lease's own commencement is the honest gate, and it is
        // the same fact `isBillableForPeriod()` refuses on.
        $notStarted = filled($lease->commencement_date)
            && CarbonImmutable::instance($lease->commencement_date)->greaterThan($today);

        $inForce = $notStarted ? null : ChargeScheduleService::pickInForce($rentRows, $today);

        $next = $rentRows
            ->filter(fn (Charge $c) => $c->is_active
                && filled($c->start_date)
                && CarbonImmutable::instance($c->start_date)->greaterThan($today))
            ->sortBy('start_date')
            ->first();

        // No row in force means the lease has not commenced — say that rather than print a rent it
        // is not charging, which is the reading the charge-schedule heading used to get wrong.
        $value = $inForce
            ? 'EGP '.number_format((float) $inForce->amount, 2)
            : __('admin.lease_summary.not_billing_yet');

        $description = $next
            ? __('admin.lease_summary.next_step', [
                'amount' => 'EGP '.number_format((float) $next->amount, 2),
                'date' => CarbonImmutable::instance($next->start_date)->format('d/m/Y'),
            ])
            : __('admin.lease_summary.no_further_steps');

        return Stat::make(__('admin.lease_summary.rent_today'), $value)
            ->description($description)
            ->descriptionIcon('heroicon-m-arrow-trending-up')
            ->color($next ? 'info' : 'gray');
    }

    /** The space, as at today — a unit given back by a contraction is not part of it. */
    private function premises(Lease $lease, CarbonImmutable $today): Stat
    {
        $units = $lease->unitsOn($today);
        $area = $lease->totalAreaSqmOn($today);

        return Stat::make(
            __('admin.lease_summary.premises'),
            $units->pluck('code')->take(3)->join(', ').($units->count() > 3 ? ' +'.($units->count() - 3) : ''),
        )
            ->description(__('admin.lease_summary.area', ['area' => number_format($area, 2)]))
            ->descriptionIcon('heroicon-m-building-storefront');
    }

    private function term(Lease $lease, CarbonImmutable $today): Stat
    {
        $expiry = $lease->expiry_date ? CarbonImmutable::instance($lease->expiry_date) : null;
        $days = $expiry ? $today->diffInDays($expiry, false) : null;

        $description = match (true) {
            $lease->isHoldover() => __('admin.lease_summary.in_holdover'),
            $days === null => __('admin.lease_summary.no_expiry'),
            $days < 0 => __('admin.lease_summary.expired_ago', ['days' => (int) abs($days)]),
            default => __('admin.lease_summary.expires_in', ['days' => (int) $days]),
        };

        return Stat::make(
            __('admin.lease_summary.term'),
            ($lease->commencement_date?->format('d/m/Y') ?? '—').' → '.($expiry?->format('d/m/Y') ?? '—'),
        )
            ->description($description)
            ->descriptionIcon('heroicon-m-calendar-days')
            ->color(match (true) {
                $lease->isHoldover() => 'warning',
                $days !== null && $days < 0 => 'danger',
                $days !== null && $days < 90 => 'warning',
                default => 'gray',
            });
    }

    /** What the tenant owes ON THIS LEASE — the figure a collections call opens with. */
    private function receivable(Lease $lease): Stat
    {
        $open = $lease->invoices()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->get(['id', 'balance', 'due_date', 'status']);

        $owed = round((float) $open->sum('balance'), 2);
        $overdue = $open->where('status', 'overdue')->count();

        return Stat::make(__('admin.lease_summary.outstanding'), 'EGP '.number_format($owed, 2))
            ->description($overdue > 0
                ? __('admin.lease_summary.overdue_invoices', ['count' => $overdue])
                : __('admin.lease_summary.open_invoices', ['count' => $open->count()]))
            ->descriptionIcon('heroicon-m-banknotes')
            ->color(match (true) {
                $overdue > 0 => 'danger',
                $owed > 0 => 'warning',
                default => 'success',
            });
    }

    /**
     * Held against contractual — the pair, never the contractual figure alone.
     *
     * A deposit that was agreed and never collected reads identically to one sitting in the bank if
     * you only show what the lease says. The shortfall is the whole reason this stat exists, and it
     * became sharper the day the deposit started tracking the rent through escalations.
     */
    private function deposit(Lease $lease): Stat
    {
        $held = app(MoveOutStatementService::class)->depositHeld($lease);
        $contractual = round((float) $lease->security_deposit, 2);
        $shortfall = round(max($contractual - $held, 0), 2);

        return Stat::make(__('admin.lease_summary.deposit'), 'EGP '.number_format($held, 2))
            ->description($shortfall > 0
                ? __('admin.lease_summary.deposit_short', [
                    'contractual' => 'EGP '.number_format($contractual, 2),
                    'shortfall' => 'EGP '.number_format($shortfall, 2),
                ])
                : __('admin.lease_summary.deposit_full', ['contractual' => 'EGP '.number_format($contractual, 2)]))
            ->descriptionIcon('heroicon-m-lock-closed')
            ->color($shortfall > 0 ? 'warning' : 'success');
    }

    /**
     * The soonest deadline that can still be missed.
     *
     * Only OPEN options: an exercised, waived or lapsed one carries no deadline, and counting it
     * would push a real one off the card. This is the same set `leases:scan-option-windows` alerts
     * on, so the screen and the email cannot disagree about what is urgent.
     */
    private function criticalDate(Lease $lease, CarbonImmutable $today): Stat
    {
        $next = $lease->options
            ->where('status', 'open')
            ->filter(fn (LeaseOption $o) => filled($o->latest_notice_date))
            ->sortBy('latest_notice_date')
            ->first();

        if (! $next) {
            return Stat::make(__('admin.lease_summary.next_critical_date'), '—')
                ->description(__('admin.lease_summary.no_options'))
                ->descriptionIcon('heroicon-m-flag')
                ->color('gray');
        }

        $deadline = CarbonImmutable::instance($next->latest_notice_date);
        $days = (int) $today->diffInDays($deadline, false);

        return Stat::make(
            __('admin.lease_summary.next_critical_date'),
            $deadline->format('d/m/Y'),
        )
            ->description(__('admin.lease_summary.option_deadline', [
                'type' => __('admin.lease_options.types.'.$next->type, [], $next->type),
                'days' => $days,
            ]))
            ->descriptionIcon('heroicon-m-flag')
            ->color($days < 0 ? 'danger' : ($days < 60 ? 'warning' : 'info'));
    }
}
