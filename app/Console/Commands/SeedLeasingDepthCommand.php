<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\LeaseCamTerm;
use App\Models\LeaseClause;
use App\Models\LeaseOption;
use App\Models\TenantSalesDeclaration;
use App\Models\User;
use App\Services\PercentageRentCalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed the leasing terms that DemoSeeder leaves empty.
 *
 * **Why this is part of the feature, not a convenience.** A lease's Options, Clauses, Percentage
 * Rent and CAM Cap tabs all render, their actions all mount, and every one of them opens EMPTY on
 * the demo books — because nothing seeds them. An empty table reads as "this is not built", not as
 * "there is no data", which is exactly how `BillUnitOwnershipsService` ran for months billing
 * nobody and how the lease's Invoices tab shipped with no actions at all: a screen nobody can tell
 * apart from an unfinished one is a screen nobody reports.
 *
 * It is also what makes the numbers checkable. Percentage rent's arithmetic can be verified in
 * isolation; whether a DECLARATION locks, bills the overage once, and re-trues the year cannot be
 * verified against a table with no rows in it.
 *
 * Idempotent per lease — it skips a lease that already carries the term, so a second run adds
 * nothing. `--fresh` removes what it wrote and lays it down again.
 */
class SeedLeasingDepthCommand extends Command
{
    protected $signature = 'atriom:seed-leasing-depth {--fresh : remove what this command wrote, then re-seed}';

    protected $description = 'Seed lease options, clauses, percentage-rent declarations and CAM cap terms on the demo books';

    public function handle(): int
    {
        $leases = Lease::where('status', 'active')->with('unit', 'tenant')->orderBy('id')->get();

        if ($leases->isEmpty()) {
            $this->error('No active leases — run `php artisan migrate:fresh --seed` first.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->removeSeeded($leases->pluck('id'));
        }

        DB::transaction(function () use ($leases) {
            $this->seedOptions($leases);
            $this->seedClauses($leases);
            $this->seedPercentageRent($leases);
            $this->seedCamTerms($leases);
        });

        $this->newLine();
        $this->info('Seeded. Counts now:');
        $this->table(['What', 'Rows'], [
            ['Lease options', LeaseOption::count()],
            ['Lease clauses', LeaseClause::count()],
            ['Sales declarations', TenantSalesDeclaration::count()],
            ['CAM cap terms', LeaseCamTerm::count()],
            ['Leases on percentage rent', Lease::where('has_percentage_rent', true)->count()],
        ]);

        return self::SUCCESS;
    }

    private function removeSeeded(mixed $leaseIds): void
    {
        LeaseOption::whereIn('lease_id', $leaseIds)->forceDelete();
        LeaseClause::whereIn('lease_id', $leaseIds)->forceDelete();
        TenantSalesDeclaration::whereIn('lease_id', $leaseIds)->forceDelete();
        LeaseCamTerm::whereIn('lease_id', $leaseIds)->forceDelete();

        $this->warn('Removed previously seeded leasing terms.');
    }

    /**
     * One option per lease, spread across the states that MATTER for the notice sweep: a window
     * open now, one closing inside the reminder horizon, one already lapsed, and one exercised.
     * A register where every row is `open` cannot show what the screen does with the others.
     */
    private function seedOptions(iterable $leases): void
    {
        $today = CarbonImmutable::today();

        $plans = [
            // closing SOON — this is the row the scan is supposed to shout about
            ['type' => 'renewal', 'status' => 'open', 'from' => $today->subDays(20), 'to' => $today->addDays(40),
                'term' => 36, 'basis' => 'uplift_percent', 'uplift' => 8.0],
            // a break option the tenant may take, priced with a penalty
            ['type' => 'termination', 'status' => 'open', 'from' => $today->addMonths(4), 'to' => $today->addMonths(7),
                'term' => null, 'basis' => null, 'penalty' => 250_000],
            // the window ran out and nobody acted — the case the register exists to make visible
            ['type' => 'renewal', 'status' => 'lapsed', 'from' => $today->subMonths(8), 'to' => $today->subMonths(5),
                'term' => 24, 'basis' => 'cpi'],
            // taken up, so the screen has a resolved row to render differently
            ['type' => 'expansion', 'status' => 'exercised', 'from' => $today->subMonths(6), 'to' => $today->subMonths(3),
                'term' => null, 'basis' => null],
            // a first-refusal on the shop next door
            ['type' => 'rofr', 'status' => 'open', 'from' => $today->subMonth(), 'to' => $today->addMonths(10),
                'term' => null, 'basis' => 'market'],
        ];

        foreach ($leases as $i => $lease) {
            if ($lease->options()->exists()) {
                continue;
            }

            $p = $plans[$i % count($plans)];

            LeaseOption::create([
                'lease_id' => $lease->id,
                'type' => $p['type'],
                'status' => $p['status'],
                'earliest_notice_date' => $p['from'],
                'latest_notice_date' => $p['to'],
                'term_months' => $p['term'] ?? null,
                'rent_basis' => $p['basis'] ?? null,
                'uplift_percent' => $p['uplift'] ?? null,
                'penalty_amount' => $p['penalty'] ?? null,
                'resolved_at' => $p['status'] === 'exercised' ? $p['to'] : null,
                'notes' => 'Seeded by atriom:seed-leasing-depth',
            ]);
        }

        $this->line('  options    ✓');
    }

    /**
     * The clauses a retail lease actually argues about. `summary` is the operator's own wording of
     * what the contract says — the system does not enforce these, it remembers them, which is why
     * every one carries a `source_reference` back to the clause number in the signed document.
     */
    private function seedClauses(iterable $leases): void
    {
        $today = CarbonImmutable::today();

        $library = [
            ['type' => 'exclusivity', 'summary' => 'No other tenant in the mall may trade primarily in sportswear.', 'ref' => 'Cl. 14.2'],
            ['type' => 'radius', 'summary' => 'Tenant may not open a competing store within 5 km for the term.', 'radius' => 5.0, 'ref' => 'Cl. 14.5'],
            ['type' => 'kick_out', 'summary' => 'Either party may terminate if annual sales fall below EGP 6,000,000, on 90 days notice.', 'amount' => 6_000_000, 'notice' => 90, 'ref' => 'Cl. 21.1'],
            // The summary must DESCRIBE the numbers beside it. This read "Rent abates 50% … for more
            // than 60 days", where 50 is the OCCUPANCY FLOOR and 60 is the NOTICE PERIOD — a
            // sentence describing a different rule from the one the columns encode, on the row an
            // operator learns the feature from.
            ['type' => 'co_tenancy', 'summary' => 'Rent abates while mall occupancy is below 50%, claimable on 60 days written notice.', 'pct' => 50.0, 'notice' => 60, 'ref' => 'Cl. 22.3'],
            ['type' => 'operating_hours', 'summary' => 'Store must trade 10:00–22:00 daily, and 10:00–00:00 through Ramadan.', 'ref' => 'Cl. 9.1'],
            ['type' => 'signage', 'summary' => 'One fascia sign to the mall standard; any change needs written approval.', 'ref' => 'Cl. 11.4'],
            ['type' => 'guarantor', 'summary' => 'Parent company guarantees all obligations for the full term.', 'ref' => 'Cl. 27'],
            // Its NOTICE PERIOD is its only number, which is the case the Trigger column got wrong:
            // it read three of the four number columns and skipped this one, and no demo clause
            // exercised it — every other row carrying a notice also carries a percentage or an
            // amount, so the dash looked correct. A seeder that only shows the easy cases is how a
            // column comes to be wrong for a month.
            ['type' => 'assignment', 'summary' => 'No assignment or sub-letting without the landlord’s prior written consent, on 30 days notice.', 'notice' => 30, 'ref' => 'Cl. 18.1'],
        ];

        foreach ($leases as $i => $lease) {
            if ($lease->clauses()->exists()) {
                continue;
            }

            // Three per lease, rotated, so no two leases carry an identical abstract.
            foreach ([0, 1, 2] as $n) {
                $c = $library[($i * 3 + $n) % count($library)];

                LeaseClause::create([
                    'lease_id' => $lease->id,
                    'type' => $c['type'],
                    'summary' => $c['summary'],
                    'threshold_pct' => $c['pct'] ?? null,
                    'threshold_amount' => $c['amount'] ?? null,
                    'radius_km' => $c['radius'] ?? null,
                    'notice_days' => $c['notice'] ?? null,
                    'applies_from' => $lease->commencement_date,
                    'applies_to' => $lease->expiry_date,
                    'source_reference' => $c['ref'],
                ]);
            }
        }

        $this->line('  clauses    ✓');
    }

    /**
     * Two leases on percentage rent, and the declarations to exercise it.
     *
     * One month is LOCKED (settled, already billed if it produced an overage), one is SUBMITTED and
     * awaiting the operator, and one month is deliberately MISSING — the case `sales:scan-missing-
     * declarations` exists for, and the one a register full of tidy rows can never show.
     */
    private function seedPercentageRent(iterable $leases): void
    {
        $targets = collect($leases)->take(2);
        $today = CarbonImmutable::today();

        // The lock is an operator's act and the service records who did it.
        $operator = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first();

        if ($operator === null) {
            $this->warn('  percentage rent — no super_admin to attribute the lock to; skipped.');

            return;
        }

        foreach ($targets as $i => $lease) {
            if (! $lease->has_percentage_rent) {
                $lease->forceFill([
                    'has_percentage_rent' => true,
                    'percentage_rent_rate' => $i === 0 ? 7.0 : 5.5,
                    // One artificial breakpoint and one natural, because they answer differently
                    // and the difference is the whole of the clause.
                    'percentage_rent_calculation_type' => $i === 0 ? 'artificial' : 'natural_breakpoint',
                    'percentage_rent_threshold' => $i === 0 ? 800_000 : null,
                    'percentage_rent_frequency' => 'monthly',
                ])->saveQuietly();
            }

            if ($lease->salesDeclarations()->exists()) {
                continue;
            }

            // −3 locked, −2 MISSING on purpose, −1 submitted and awaiting the operator.
            foreach ([3 => 'locked', 1 => 'submitted'] as $monthsAgo => $status) {
                $start = $today->subMonths($monthsAgo)->startOfMonth();
                $sales = $i === 0
                    ? ($monthsAgo === 3 ? 1_240_000 : 910_000)
                    : ($monthsAgo === 3 ? 2_100_000 : 640_000);

                $declaration = TenantSalesDeclaration::create([
                    'lease_id' => $lease->id,
                    'period_start' => $start,
                    'period_end' => $start->endOfMonth(),
                    'declared_sales' => $sales,
                    'gross_sales' => $sales,
                    'is_estimate' => false,
                    'declared_at' => $start->endOfMonth()->addDays(5),
                    'status' => 'submitted',
                ]);

                // LOCKED goes through the real service, never by writing the columns. Locking is
                // what computes the overage, freezes it on the row and raises the invoice for it —
                // stamping `status` and `locked_at` by hand produces a row that LOOKS settled,
                // carries a stored overage of 0.00 and has no invoice behind it, which is a worse
                // fixture than none: every figure on the screen reads as if the month were closed.
                if ($status === 'locked') {
                    app(PercentageRentCalculationService::class)->lock(
                        $declaration,
                        $operator,
                        'Seeded by atriom:seed-leasing-depth',
                    );
                }
            }
        }

        $this->line('  percentage rent ✓  (one month deliberately missing, for the scan)');
    }

    /**
     * EVERY CAP SHAPE THE ENGINE CAN RESOLVE, ONE PER LEASE, EACH LABELLED IN ITS OWN NOTES.
     *
     * A cap only shows itself when the apportionment tries to exceed it, so a demo carrying one
     * absolute cap teaches one branch of six and leaves the rest indistinguishable from unbuilt —
     * the same reasoning that made this command exist at all.
     *
     * The pairs are the point. `yoy` compounding and `yoy` simple sit on the SAME base and rate so
     * the two ceilings can be read side by side (19,845 against 19,800 in 2026); `both` states an
     * absolute ceiling deliberately ABOVE its year-on-year one, so which of the two won is provable
     * rather than assumed; and one cap is set far above any share it could ever meet, because "a
     * cap that does not bite is invisible" is a fact about the feature nobody can check against a
     * dataset where every cap bites.
     *
     * `effective_year` is 2025, not this year: the resolver takes the latest term ≤ the reconciled
     * year, so a 2025 term governing a 2026 pool is the effective-dating rule live in the data
     * rather than described in a doc. The 2025 pool is already billed, and a billed allocation is
     * never re-touched, so nothing already reconciled moves.
     *
     * @var list<array{tenant: ?string, notes: string, columns: array<string, mixed>}>
     */
    private const CAM_CAP_SHAPES = [
        [
            'tenant' => 'California Gym',
            'notes' => 'DEMO: absolute cap that BITES — the landlord absorbs everything above 25,000.',
            'columns' => ['cap_type' => 'absolute', 'cap_absolute_amount' => 25_000],
        ],
        [
            'tenant' => 'Cook Door',
            'notes' => 'DEMO: absolute cap set far ABOVE any share — a cap that never bites absorbs nothing.',
            'columns' => ['cap_type' => 'absolute', 'cap_absolute_amount' => 400_000],
        ],
        [
            'tenant' => 'El Ezaby Pharmacy',
            'notes' => 'DEMO: year-on-year, COMPOUNDING — 18,000 base × 1.05^n. Compare with Smart Gym.',
            'columns' => [
                'cap_type' => 'yoy', 'base_year' => 2024, 'base_year_amount' => 18_000,
                'yoy_pct' => 0.05, 'compounding' => true,
            ],
        ],
        [
            'tenant' => 'Smart Gym',
            'notes' => 'DEMO: year-on-year, SIMPLE — 18,000 base × (1 + 0.05n). Same clause, one toggle apart.',
            'columns' => [
                'cap_type' => 'yoy', 'base_year' => 2024, 'base_year_amount' => 18_000,
                'yoy_pct' => 0.05, 'compounding' => false,
            ],
        ],
        [
            'tenant' => 'Abou El Sid',
            'notes' => 'DEMO: BOTH — absolute 30,000 against a year-on-year 16,537.50. The tighter one wins.',
            'columns' => [
                'cap_type' => 'both', 'cap_absolute_amount' => 30_000,
                'base_year' => 2024, 'base_year_amount' => 15_000, 'yoy_pct' => 0.05, 'compounding' => true,
            ],
        ],
        [
            'tenant' => 'Cleopatra Wellness Spa',
            'notes' => 'DEMO: scope = CONTROLLABLE — the ceiling bites on managed cost only; rates, insurance '
                .'and utilities pass through above it. Set the pool\'s controllable % to see it move.',
            'columns' => [
                'cap_type' => 'absolute', 'cap_absolute_amount' => 12_000,
                'cap_scope' => LeaseCamTerm::SCOPE_CONTROLLABLE,
            ],
        ],
        [
            'tenant' => 'Zööba',
            // TWO rows, because one cannot show it. Headroom is banked by a year that comes in
            // UNDER its ceiling and spent by a later, tighter one — so a single term demonstrates
            // carry-forward only if the same ceiling both clears one year and bites the next, which
            // no real ladder does. The 2025 ceiling sits above this lease's 2025 share and the 2026
            // one below its 2026 share, which is the whole mechanism in two rows.
            'notes' => 'DEMO: CARRY-FORWARD, year 1 — the ceiling is ABOVE this year\'s share, so the '
                .'difference is banked for a later spike instead of being lost.',
            'columns' => [
                'cap_type' => 'absolute', 'cap_absolute_amount' => 45_000, 'cap_carry_forward' => true,
            ],
            'also' => [
                'year' => 2026,
                'notes' => 'DEMO: CARRY-FORWARD, year 2 — a tighter ceiling that DRAWS on what year 1 banked. '
                    .'It absorbs nothing until the earlier year has actually been reconciled under its cap: '
                    .'`camCapHeadroomBankedBefore()` reads the ALLOCATIONS, not the terms.',
                'columns' => [
                    'cap_type' => 'absolute', 'cap_absolute_amount' => 10_000, 'cap_carry_forward' => true,
                ],
            ],
        ],
        [
            'tenant' => 'Town Team',
            // Deliberately BELOW this lease's area share. Above it, the shares would promise away
            // more of the pool than it holds and `generateAllocations` refuses the whole run —
            // which is the guard working, and a poor default for a dataset meant to be reconciled.
            'notes' => 'DEMO: a STATED share and NO cap — the percentage the contract simply names. '
                .'Below the area share, so less of the pool is recovered and the landlord bears the rest.',
            'columns' => ['cap_type' => null, 'stated_share_pct' => 1.5],
        ],
    ];

    /**
     * A lease whose cap is later ENDED — two rows, which is the only way to express it.
     *
     * Deleting the capped year's term would uncap the years already reconciled under it; a later
     * term stating no ceiling supersedes it from its own year on and leaves the past alone.
     *
     * @var array{tenant: string, capped_year: int, ends_year: int, amount: int}
     */
    private const CAM_CAP_ENDED = [
        'tenant' => 'Mobica', 'capped_year' => 2024, 'ends_year' => 2027, 'amount' => 8_000,
    ];

    private function seedCamTerms(iterable $leases): void
    {
        $leases = collect($leases);
        $spare = $leases->filter(fn ($l) => ! $l->camTerms()->exists())->values();
        $taken = [];

        foreach (self::CAM_CAP_SHAPES as $shape) {
            // Prefer the named tenant so the demo reads as a mall rather than as a fixture, but
            // never DEPEND on it: this command must still lay the matrix down on a database
            // DemoSeeder never touched.
            $lease = $leases->first(fn ($l) => $l->tenant?->name === $shape['tenant'] && ! in_array($l->id, $taken, true))
                ?? $spare->first(fn ($l) => ! in_array($l->id, $taken, true));

            // A shape whose lease already carries a term is SKIPPED, not re-keyed onto the next
            // one. Keying the shape to the loop index instead is what this replaced, and it cost
            // the demo a whole branch: the first lease already carried a DemoSeeder cap, so the
            // `absolute` arm never ran and no absolute cap existed anywhere on the books.
            if ($lease === null || $lease->camTerms()->exists()) {
                continue;
            }

            $taken[] = $lease->id;

            LeaseCamTerm::create([
                'lease_id' => $lease->id,
                // 2025, not this year: a 2025 term governing a 2026 pool IS the effective-dating
                // rule, and the resolver takes the latest term on or before the reconciled year.
                'effective_year' => 2025,
                // A FRACTION, not a percent. The form shows the operator 5 and stores 0.05
                // (`dehydrateStateUsing`), and `resolveCeiling()` computes base × (1 + pct)^years —
                // so writing 5.0 straight to the model states a 500%-a-year cap that can never bite.
                'notes' => $shape['notes'],
            ] + $shape['columns']);

            // A shape that needs a LADDER rather than a single ceiling — carry-forward is the one,
            // and a second row is the only way to state it.
            if (isset($shape['also'])) {
                LeaseCamTerm::create([
                    'lease_id' => $lease->id,
                    'effective_year' => $shape['also']['year'],
                    'notes' => $shape['also']['notes'],
                ] + $shape['also']['columns']);
            }
        }

        $ended = $leases->first(fn ($l) => $l->tenant?->name === self::CAM_CAP_ENDED['tenant'] && ! in_array($l->id, $taken, true))
            ?? $spare->first(fn ($l) => ! in_array($l->id, $taken, true));

        if ($ended !== null && ! $ended->camTerms()->exists()) {
            $ended->camTerms()->create([
                'effective_year' => self::CAM_CAP_ENDED['capped_year'],
                'cap_type' => 'absolute',
                'cap_absolute_amount' => self::CAM_CAP_ENDED['amount'],
                'notes' => 'DEMO: capped from '.self::CAM_CAP_ENDED['capped_year'].' — and ENDED by the row below it.',
            ]);
            $ended->camTerms()->create([
                'effective_year' => self::CAM_CAP_ENDED['ends_year'],
                'cap_type' => null,
                'notes' => 'DEMO: no cap from '.self::CAM_CAP_ENDED['ends_year'].' on. The earlier ceiling still '
                    .'governs every year before this one — deleting it would have uncapped those too.',
            ]);
        }

        $this->line('  CAM caps   ✓  ('.LeaseCamTerm::count().' terms: absolute · yoy compounding · yoy simple · '
            .'both · controllable · carry-forward · stated share with no cap · a cap that ends)');
    }
}
