<?php

namespace App\Support;

/**
 * Every service must be reachable from something a person or a schedule can start.
 *
 * **Why this registry exists.** Every gate in this project checks that a thing is CLASSIFIED —
 * property isolation, deletion policy, GL sources, search, screen guides, posting dates. None
 * checked that a thing is REACHABLE. On 2026-08-18 a sweep traced every class in `app/Services` to
 * an entry point and found **four that had none**:
 *
 *  - `BillUnitOwnershipsService` — the monthly صيانة run. No command, no job, no schedule entry, no
 *    button. Every unit owner went un-billed in production for a month, while the service's own
 *    docblock spoke of "the scheduled one".
 *  - `TransferUnitOwnershipService` — a unit could be resold and there was no way to record it.
 *  - `AssetStatementPdfService` — the owner's property statement, orphaned when the `/owner` panel
 *    was removed and its button went with it.
 *  - `VendorScorecardService` — built with seven regression tests and no screen, sitting in
 *    `docs/ROADMAP.md` as work to do while the work was done.
 *
 * All four were fully built, fully tested and completely unusable. **Tests are what hid them**: a
 * service with a green test file looks maintained, and `grep` says it is referenced. This gate asks
 * a different question — *could an operator ever cause this to run?*
 *
 * **Reachability is TRANSITIVE, and that is the whole design.** A service called only by other
 * services is fine when one of those is itself reachable (`RecordLeaseEventService` is called by
 * eight lease services, all reachable from the panel). It is NOT fine when a cluster of services
 * only call each other — that is an orphaned island, and a rule of "referenced by any service"
 * would pass it. So the gate seeds from the layers a person or a schedule can actually start —
 * Filament, console, jobs, HTTP, listeners, observers, models, providers, routes — and takes the
 * fixpoint from there. Tests and seeders are deliberately NOT seeds: they are exactly what made the
 * four above look alive.
 *
 * The gate is `Tests\Feature\Scenarios\ServiceReachabilityConformanceTest` — named in prose rather
 * than an `@see` tag, because the `Tests\` namespace is dev-only autoload and must not be imported
 * into `app/` (pint's fully_qualified_strict_types fixer will hoist a tagged FQCN into a `use`).
 */
final class ServiceReachability
{
    /**
     * Directories whose files can start work in production. The seed set for the closure.
     *
     * `database/` is absent on purpose — `DemoSeeder` calling a service proves only that the demo
     * data uses it. That is precisely how `BillUnitOwnershipsService` looked maintained.
     *
     * @var array<int, string>
     */
    public const ENTRY_POINTS = [
        'app/Filament',
        'app/Console',
        'app/Jobs',
        'app/Http',
        'app/Listeners',
        'app/Observers',
        'app/Models',
        'app/Providers',
        'app/Notifications',
        'routes',
    ];

    /**
     * Services with no entry point that are nonetheless correct, each with the reason.
     *
     * Keep this SHORT and keep the reasons concrete. "Will be used later" is not a reason — that is
     * what `VendorScorecardService` said for a month. If a service is genuinely for a future slice,
     * the honest options are to delete it or to ship the screen; an exemption here should mean the
     * class is reached by a mechanism this scanner cannot see, not that nothing reaches it.
     *
     * @var array<string, string>
     */
    public const EXEMPT = [
        // Example shape, kept as documentation rather than an entry:
        // FooService::class => 'Resolved by FQCN string from config/foo.php, which the scanner does not parse.',
    ];
}
