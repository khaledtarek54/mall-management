<?php

namespace App\Support;

use App\Filament\Admin\Widgets\ActionRequired;
use App\Filament\Admin\Widgets\ArAging;
use App\Filament\Admin\Widgets\EnergyConsumptionTrend;
use App\Filament\Admin\Widgets\EtaCompliance;
use App\Filament\Admin\Widgets\ExpiringLeases;
use App\Filament\Admin\Widgets\LeasingPipeline;
use App\Filament\Admin\Widgets\MallStats;
use App\Filament\Admin\Widgets\MarketingPerformance;
use App\Filament\Admin\Widgets\MonthlyCloseStats;
use App\Filament\Admin\Widgets\MonthlyRevenueTrend;
use App\Filament\Admin\Widgets\MyAssignedWork;
use App\Filament\Admin\Widgets\OpenTenantRequests;
use App\Filament\Admin\Widgets\PayrollStats;
use App\Filament\Admin\Widgets\RecentPayments;
use App\Filament\Admin\Widgets\SetupGuide;
use App\Filament\Admin\Widgets\TenantMix;
use App\Filament\Admin\Widgets\TopTenants;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * **The single registry of what each role's dashboard is.**
 *
 * It replaced thirteen separate `allowedRoles()` lists, one per widget. Declaring visibility
 * widget-by-widget answers "who sees THIS?" but nobody could answer "what does a marketing user
 * see?" without opening thirteen files and intersecting them by hand — and the answer, it turned
 * out, was **nothing**. Six of the fifteen roles (owner, marketing, hr, technician, vendor,
 * mall_admin) logged in to a completely blank page; `mall_admin`, whose own description is "a
 * manager for their assigned properties", among them.
 *
 * Read by role, the holes are visible on sight, and `DashboardLayoutConformanceTest` fails the
 * build on an empty role or an unregistered widget.
 *
 * ---
 *
 * **Composition rules**
 *
 * 1. **A dashboard answers "what should I do today?"** — not "here is every number we have".
 *    A manager was getting eleven widgets and a 2,900px scroll; the panels that mattered were
 *    below the fold. Anything a role would not act on daily belongs on its module's page, not here.
 * 2. **Nothing shows money to a role that doesn't handle money.** See `MONEY_ROLES` — the AR and
 *    collections figures in `MallStats` are filtered by it, and `ActionRequired` gates each of its
 *    cards on the permission for the module it links to.
 * 3. **A widget appears here or it does not appear at all.** Filament auto-discovers everything in
 *    the widget directory, so a widget that forgets to gate itself is published to every role on
 *    the panel — which is exactly how `MonthlyCloseStats` (invoices issued, collections rate,
 *    outstanding AR, and all five ageing buckets) ended up on the HR and marketing dashboards.
 *    `NOT_ON_DASHBOARD` names those deliberately, with the reason.
 */
final class DashboardLayout
{
    /**
     * A general manager's dashboard: the to-do list, the KPIs, the money at risk, the trend,
     * and the two forward-looking queues. Deliberately six panels — LeasingPipeline (subsumed by
     * MallStats occupancy + ExpiringLeases), TenantMix, TopTenants, RecentPayments and the energy
     * trend were pushed to the roles that actually work them.
     */
    private const MANAGER = [
        ActionRequired::class,
        MallStats::class,
        ArAging::class,
        MonthlyRevenueTrend::class,
        ExpiringLeases::class,
        OpenTenantRequests::class,
    ];

    /** @var array<string, array<class-string>> role => the widgets it sees, in render order */
    public const LAYOUTS = [
        // Setup guide first: these are the roles that onboard a property. It removes itself once
        // every step is done, so an established mall doesn't carry a permanent "all set" banner.
        'super_admin' => [SetupGuide::class, ...self::MANAGER],

        'manager' => self::MANAGER,

        // FR-USR-01: "a manager for their assigned properties, plus the right to import data" —
        // so it gets a manager's dashboard. It had none at all.
        'mall_admin' => self::MANAGER,

        // Read-only stakeholders + auditors: the numbers, none of the operational queues they
        // cannot act on.
        'viewer' => [
            MallStats::class,
            ArAging::class,
            MonthlyRevenueTrend::class,
            ExpiringLeases::class,
            TopTenants::class,
        ],

        // Jawad's oversight of the properties he owns: performance and money, no work queues.
        // An owner is not staff — the request board and the setup guide are not his to action.
        'owner' => [
            MallStats::class,
            ArAging::class,
            MonthlyRevenueTrend::class,
            TopTenants::class,
            ExpiringLeases::class,
        ],

        'leasing' => [
            SetupGuide::class,
            ActionRequired::class,
            MallStats::class,
            LeasingPipeline::class,
            ExpiringLeases::class,
            TopTenants::class,
            TenantMix::class,
        ],

        'operations' => [
            ActionRequired::class,
            MallStats::class,
            OpenTenantRequests::class,
            EnergyConsumptionTrend::class,
        ],

        'accounting' => [
            ActionRequired::class,
            MallStats::class,
            ArAging::class,
            MonthlyRevenueTrend::class,
            RecentPayments::class,
            EtaCompliance::class,
        ],

        // Was blank. The marketing levy is charged to every tenant and the operator answers to the
        // owner for how it is spent, so the budget-vs-spend position is the job. Tenant mix is the
        // campaign-planning view of who is actually in the mall.
        'marketing' => [
            MarketingPerformance::class,
            TenantMix::class,
        ],

        // Was blank. Headcount and the state of this month's payroll run — HR's only daily figures.
        'hr' => [
            PayrollStats::class,
        ],

        // FR-USR-04, the role the assignment scope exists for: "sees only work assigned to them".
        // Its dashboard is that work and nothing else.
        'technician' => [
            MyAssignedWork::class,
        ],

        // FR-USR-03, an external contractor: the maintenance board it is assigned to, view-only.
        // No tenant financials, no leases, no portfolio KPIs.
        'vendor' => [
            MyAssignedWork::class,
        ],

        // The queue supervisor: the board, plus the alerts that are about that board.
        'coordinator' => [
            ActionRequired::class,
            OpenTenantRequests::class,
        ],

        // Front desk: intake and tracking, no work authority.
        'customer_service' => [
            OpenTenantRequests::class,
        ],
    ];

    /**
     * Widgets that exist but must never be composed onto the dashboard, and why.
     *
     * Filament's `discoverWidgets()` registers every class in the widget directory with the panel.
     * Before this list, "not registered on the dashboard" was a claim in a docblock rather than
     * anything enforced — and it was false.
     *
     * @var array<class-string, string>
     */
    public const NOT_ON_DASHBOARD = [
        MonthlyCloseStats::class => 'Belongs to the Reports page, which drives it with a period picker. On the dashboard it had no period, silently defaulted to "now", and duplicated MallStats — while publishing the whole property\'s receivables to every role on the panel.',
    ];

    /**
     * Roles that handle money, and may therefore see collections and receivables figures.
     *
     * Leasing and operations are deliberately absent: they need occupancy and contractual rent to
     * do their jobs, not the property's outstanding AR.
     *
     * @var array<string>
     */
    public const MONEY_ROLES = ['super_admin', 'manager', 'mall_admin', 'viewer', 'owner', 'accounting'];

    /**
     * The widgets a user's dashboard is built from, in order, de-duplicated across their roles.
     *
     * A user with several roles gets the union — the layout of their most senior role first, then
     * anything extra a secondary role adds, so a manager-who-is-also-accounting still reads as a
     * manager's dashboard.
     *
     * @return array<class-string>
     */
    public static function widgetsFor(?User $user = null): array
    {
        $user ??= Auth::user();

        if (! $user) {
            return [];
        }

        $widgets = [];

        // Iterate LAYOUTS (not the user's role list) so the order is the registry's, and therefore
        // stable no matter what order Spatie returns roles in.
        foreach (self::LAYOUTS as $role => $roleWidgets) {
            if (! $user->hasRole($role)) {
                continue;
            }

            foreach ($roleWidgets as $widget) {
                $widgets[$widget] = true;
            }
        }

        return array_keys($widgets);
    }

    /** Is this widget part of the given user's dashboard? */
    public static function allows(string $widget, ?User $user = null): bool
    {
        return in_array($widget, self::widgetsFor($user), true);
    }

    /** Does this user hold a role that may see money figures? */
    public static function seesMoney(?User $user = null): bool
    {
        $user ??= Auth::user();

        return (bool) $user?->hasAnyRole(self::MONEY_ROLES);
    }

    /**
     * Every widget class the registry composes, across all roles.
     *
     * @return array<class-string>
     */
    public static function allWidgets(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::LAYOUTS))));
    }
}
