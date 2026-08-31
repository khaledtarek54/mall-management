<?php

/*
|--------------------------------------------------------------------------
| The legal terms lived only in the PDF (2026-08-19)
|--------------------------------------------------------------------------
| `docs/benchmarks/yardi/01-yardi-lease-administration.md` §7 describes the lease abstract — the
| structured record of terms that do not reduce to money: use, exclusivity, radius, co-tenancy,
| kick-out, assignment, insurance, operating hours, signage, parking, repairs, guarantor.
|
| Its reason for existing is the test this file is built around, quoted because it names the exact
| failure rather than a feature:
|
| > "co-tenancy and kick-out clauses are *contingent money*. … In Atriom these clauses live only in
| > the uploaded PDF, so nothing can act on them and nothing can even report 'how many of our leases
| > have a co-tenancy trigger tied to the anchor we are about to lose'."
|
| So the deliverable is that the question becomes ANSWERABLE. The last test here is that question,
| asked literally.
|
| **What this deliberately does not do:** abate rent by itself. The benchmark notes a well-run
| system abates automatically; here the trigger is recorded and surfaced, and raising the abatement
| stays a deliberate act through `LeaseReliefService`. Same shape as a violation fine and a
| percentage-rent overage — recording and charging are two steps, because the second is somebody's
| decision and lands on a tenant's bill.
*/

use App\Filament\Admin\RelationManagers\LeaseClausesRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Lease;
use App\Models\LeaseClause;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->lease = makeLease(makeUnit($this->asset));
});

it('abstracts a clause against its lease', function () {
    $clause = $this->lease->clauses()->create([
        'type' => LeaseClause::TYPE_EXCLUSIVITY,
        'summary' => 'No other coffee operator above 60 m² in the centre.',
        'source_reference' => 'cl. 14.3',
    ]);

    expect($this->lease->fresh()->clauses)->toHaveCount(1)
        ->and($clause->label())->toBe('Exclusivity');
});

it('keeps the numbers the business reasons about', function () {
    $radius = $this->lease->clauses()->create([
        'type' => LeaseClause::TYPE_RADIUS,
        'radius_km' => 5,
        'summary' => 'No second branch within 5 km.',
    ]);

    $coTenancy = $this->lease->clauses()->create([
        'type' => LeaseClause::TYPE_CO_TENANCY,
        'threshold_pct' => 70,
        'notice_days' => 30,
    ]);

    expect((float) $radius->radius_km)->toBe(5.0)
        ->and((float) $coTenancy->threshold_pct)->toBe(70.0)
        ->and($coTenancy->notice_days)->toBe(30);
});

/**
 * A clause can lapse. A co-tenancy protection commonly runs for the first years of a term only, so
 * "is this in force?" is a question about a date, not about the row existing.
 */
it('knows whether a clause is in force on a date', function () {
    $lapsed = $this->lease->clauses()->create([
        'type' => LeaseClause::TYPE_CO_TENANCY,
        'applies_from' => '2026-01-01',
        'applies_to' => '2028-12-31',
    ]);

    expect($lapsed->isInForceOn(CarbonImmutable::parse('2027-06-01')))->toBeTrue()
        // The boundary day itself counts — the same inclusive convention the charge schedule and
        // the premises pivot use, so a reader who knows one knows all three.
        ->and($lapsed->isInForceOn(CarbonImmutable::parse('2028-12-31')))->toBeTrue()
        ->and($lapsed->isInForceOn(CarbonImmutable::parse('2029-01-01')))->toBeFalse()
        ->and($lapsed->isInForceOn(CarbonImmutable::parse('2025-12-31')))->toBeFalse();
});

it('treats an open-ended clause as always in force', function () {
    $standing = $this->lease->clauses()->create(['type' => LeaseClause::TYPE_SIGNAGE]);

    expect($standing->isInForceOn(CarbonImmutable::parse('2099-01-01')))->toBeTrue();
});

/** The scope and the predicate must agree, or a screen and a report disagree about the same clause. */
it('scopes to the clauses in force, matching the predicate', function () {
    $this->lease->clauses()->create([
        'type' => LeaseClause::TYPE_CO_TENANCY, 'applies_to' => '2026-06-30',
    ]);
    $live = $this->lease->clauses()->create(['type' => LeaseClause::TYPE_SIGNAGE]);

    $on = CarbonImmutable::parse('2027-01-01');
    $inForce = LeaseClause::query()->inForceOn($on)->get();

    expect($inForce->pluck('id')->all())->toBe([$live->id])
        ->and($inForce->every(fn (LeaseClause $c) => $c->isInForceOn($on)))->toBeTrue();
});

/** The value set is enforced on every save, so a typo cannot invent a clause type. */
it('refuses a clause type outside the value set', function () {
    // `DomainException`, so it renders as a message and a redirect rather than a 500 — the
    // house rule for anything the operator did that is not allowed.
    expect(fn () => $this->lease->clauses()->create(['type' => 'handshake']))
        ->toThrow(DomainException::class);
});

/**
 * **The question the benchmark says nothing could answer.**
 *
 * "How many of our leases have a co-tenancy trigger tied to the anchor we are about to lose?" — a
 * portfolio scan by clause type, which is exactly what a PDF cannot support and a typed row can.
 */
it('answers which leases carry contingent-money clauses, across the portfolio', function () {
    $exposed = $this->lease;
    $exposed->clauses()->create(['type' => LeaseClause::TYPE_CO_TENANCY, 'threshold_pct' => 70]);

    $alsoExposed = makeLease(makeUnit($this->asset));
    $alsoExposed->clauses()->create(['type' => LeaseClause::TYPE_KICK_OUT, 'threshold_amount' => 4_000_000]);

    // A lease with clauses, but none that can cost money — the control that stops this passing by
    // simply returning every lease that has an abstract.
    $safe = makeLease(makeUnit($this->asset));
    $safe->clauses()->create(['type' => LeaseClause::TYPE_SIGNAGE]);

    $exposedLeaseIds = LeaseClause::query()
        ->liveExposure()
        ->pluck('lease_id')
        ->unique()
        ->values();

    expect($exposedLeaseIds->all())->toEqualCanonicalizing([$exposed->id, $alsoExposed->id])
        ->and($exposedLeaseIds)->not->toContain($safe->id);
});

/**
 * **The bug the review found (2026-08-19), pinned.**
 *
 * The first version of the question filtered by clause type and by the clause being in force, and
 * reported a TERMINATED lease as exposed: its co-tenancy clause was open-ended, so it read as in
 * force for ever while the tenancy it protected had ended. An operator asking "who can claim an
 * abatement if the anchor leaves?" would have been handed a tenant who had already left.
 *
 * Found by running the query on seeded data, not by a failing test — which is why the three
 * conditions are now bundled in one scope rather than composed at each call site.
 */
it('does not report a dead lease as exposed', function () {
    $live = $this->lease;
    $live->clauses()->create(['type' => LeaseClause::TYPE_CO_TENANCY, 'threshold_pct' => 70]);

    foreach (Lease::TERMINAL_STATUSES as $ended) {
        $dead = makeLease(makeUnit($this->asset));
        $dead->clauses()->create(['type' => LeaseClause::TYPE_CO_TENANCY, 'threshold_pct' => 65]);
        $dead->forceFill(['status' => $ended])->saveQuietly();
    }

    $exposed = LeaseClause::query()->liveExposure()->pluck('lease_id')->unique();

    expect($exposed->all())->toBe([$live->id]);
});

/**
 * And the control for that refusal: the pure type filter still answers the OTHER question — "every
 * kick-out clause we have ever agreed" — including the dead ones. A scope that quietly hid ended
 * leases from both questions would be a different bug.
 */
it('still counts clauses on ended leases when asked for the type, not the exposure', function () {
    $dead = makeLease(makeUnit($this->asset));
    $dead->clauses()->create(['type' => LeaseClause::TYPE_KICK_OUT, 'threshold_amount' => 1_000_000]);
    $dead->forceFill(['status' => 'terminated'])->saveQuietly();

    expect(LeaseClause::query()->contingentMoney()->count())->toBe(1)
        ->and(LeaseClause::query()->liveExposure()->count())->toBe(0);
});

/** A clause belongs to its lease's property, so the register cannot leak across malls. */
it('is scoped to the property the lease sits in', function () {
    $this->lease->clauses()->create(['type' => LeaseClause::TYPE_USE]);

    $elsewhere = makeLease(makeUnit(makeAsset()));
    $elsewhere->clauses()->create(['type' => LeaseClause::TYPE_USE]);

    expect(LeaseClause::query()->whereHas('lease.unit', fn ($q) => $q->where('asset_id', $this->asset->id))->count())
        ->toBe(1);
});

/** Deleting a lease takes its abstract with it — the clause has no meaning without the contract. */
it('goes with the lease', function () {
    $this->lease->clauses()->create(['type' => LeaseClause::TYPE_USE]);

    $this->lease->forceDelete();

    expect(LeaseClause::withTrashed()->count())->toBe(0);
});

/**
 * The surface, driven. A register nobody can open is the failure this project keeps finding — the
 * abstract only earns its place if an operator can read it on the lease they are looking at.
 */
it('shows the abstract on the lease page', function () {
    $this->actingAs(makeUser('leasing', [$this->asset->id]));
    Filament::setTenant($this->asset);

    $clause = $this->lease->clauses()->create([
        'type' => LeaseClause::TYPE_CO_TENANCY,
        'threshold_pct' => 70,
        'summary' => 'Rent abates 50% if centre occupancy falls below 70%.',
    ]);

    Livewire::test(LeaseClausesRelationManager::class, [
        'ownerRecord' => $this->lease->fresh(),
        'pageClass' => EditLease::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$clause]);
});

it('records a clause through the real form', function () {
    $this->actingAs(makeUser('leasing', [$this->asset->id]));
    Filament::setTenant($this->asset);

    Livewire::test(LeaseClausesRelationManager::class, [
        'ownerRecord' => $this->lease,
        'pageClass' => EditLease::class,
    ])
        ->callTableAction('create', data: [
            'type' => LeaseClause::TYPE_RADIUS,
            'summary' => 'No second branch within 5 km of the centre.',
            'radius_km' => 5,
            'source_reference' => 'cl. 18.2',
        ])
        ->assertHasNoTableActionErrors();

    expect((float) $this->lease->fresh()->clauses()->sole()->radius_km)->toBe(5.0);
});

/** A role without lease-edit rights reads the abstract and cannot change it. */
it('withholds the write actions from a role that cannot edit leases', function () {
    $viewer = makeUser('viewer', [$this->asset->id]);

    expect($viewer->can('leases.edit'))->toBeFalse();
});

/**
 * A soft-deleted lease drops out too — and that is a different mechanism from the status column, so
 * it gets its own assertion rather than riding on the terminal-status one. It works because
 * `whereHas('lease')` honours the lease's own SoftDeletes global scope; if that relation were ever
 * given `withTrashed()` for some unrelated reason, this is what would notice.
 */
it('does not report a soft-deleted lease as exposed', function () {
    $live = $this->lease;
    $live->clauses()->create(['type' => LeaseClause::TYPE_CO_TENANCY, 'threshold_pct' => 70]);

    $removed = makeLease(makeUnit($this->asset));
    $removed->clauses()->create(['type' => LeaseClause::TYPE_CO_TENANCY, 'threshold_pct' => 65]);
    $removed->delete();

    expect(LeaseClause::query()->liveExposure()->pluck('lease_id')->unique()->all())
        ->toBe([$live->id]);
});

/**
 * A NUMBER BELONGS TO ITS CLAUSE TYPE (2026-08-31).
 *
 * Found by driving the edit modal, not by a test. Change a co-tenancy clause to signage and the
 * 70% occupancy floor stayed on the row: the form had just hidden that field, so it was not
 * submitted and the model kept what was already there. The result is a number no screen can show
 * and no operator can correct — and the clause register printed it as `70.00%` beside the word
 * *Signage*.
 *
 * The form hiding a field stops it being SUBMITTED; only the model can stop it being KEPT.
 */
it('drops a number the clause type cannot carry', function () {
    $clause = $this->lease->clauses()->create([
        'type' => LeaseClause::TYPE_CO_TENANCY,
        'threshold_pct' => 70,
        'notice_days' => 30,
    ]);

    expect((float) $clause->threshold_pct)->toBe(70.0);

    // The operator re-classifies it. Signage carries neither an occupancy floor nor a notice period.
    $clause->update(['type' => LeaseClause::TYPE_SIGNAGE]);

    expect($clause->fresh()->threshold_pct)->toBeNull()
        ->and($clause->fresh()->notice_days)->toBeNull();
});

/**
 * The control, and it is the half that matters: a hook that nulled everything would satisfy the
 * test above and quietly empty the register.
 */
it('keeps every number the clause type does carry', function () {
    $coTenancy = $this->lease->clauses()->create([
        'type' => LeaseClause::TYPE_CO_TENANCY, 'threshold_pct' => 70, 'notice_days' => 30,
    ]);
    $kickOut = $this->lease->clauses()->create([
        'type' => LeaseClause::TYPE_KICK_OUT, 'threshold_amount' => 250000, 'notice_days' => 90,
    ]);
    $radius = $this->lease->clauses()->create([
        'type' => LeaseClause::TYPE_RADIUS, 'radius_km' => 5,
    ]);
    $assignment = $this->lease->clauses()->create([
        'type' => LeaseClause::TYPE_ASSIGNMENT, 'notice_days' => 30,
    ]);

    expect((float) $coTenancy->fresh()->threshold_pct)->toBe(70.0)
        ->and($coTenancy->fresh()->notice_days)->toBe(30)
        ->and((float) $kickOut->fresh()->threshold_amount)->toBe(250000.0)
        ->and($kickOut->fresh()->notice_days)->toBe(90)
        ->and((float) $radius->fresh()->radius_km)->toBe(5.0)
        ->and($assignment->fresh()->notice_days)->toBe(30);
});

/**
 * The form and the model must not drift: a field the form still OFFERS while the model nulls it is
 * a box the operator types into that never saves, which reads as the app losing their work.
 */
it('offers exactly the numbers the model will keep', function () {
    foreach (LeaseClause::TYPES as $type) {
        foreach (array_keys(LeaseClause::NUMBERS_BY_TYPE) as $column) {
            $clause = $this->lease->clauses()->create(['type' => $type, $column => 5]);

            expect($clause->fresh()->{$column} !== null)
                ->toBe(LeaseClause::carriesNumber($column, $type), "{$type}.{$column}");
        }
    }
});
