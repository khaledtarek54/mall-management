<?php

use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Services\AssignRentableItemService;
use App\Services\ConvertLeaseToHoldoverService;
use App\Services\LeaseRenewalService;
use App\Services\LeaseTerminationService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * A renewal can be signed after the old term has ended — and so can a parking bay be let.
 *
 * The third and fourth doors of the LE-04 shape, and the last two still shut. `leases:expire` runs
 * at 05:15 and projects every active lease past its term to `expired`; `expired` is in
 * `TERMINAL_STATUSES`, so the immutability hook refused every commercial write, and both the Renew
 * button and `LeaseRenewalService` asked for `status === 'active'`. Holding over was given its
 * carve-out when LE-04 was found unreachable and closing out was given one when the same hole was
 * reported against termination — **renewing, the commonest of the three, was not**.
 *
 * That matters more than the other two because a renewal is routinely signed WEEKS after the old
 * term ran out: the parties negotiate, the tenant keeps trading, and the document is dated back to
 * the day after the old expiry so the tenancy has no gap. Yardi renews from the lease whatever its
 * Current/Past status, and MRI and Entrata do the same. The only route Atriom left was to convert to
 * HOLDOVER first and renew the resumed lease — which prices those months at the holdover uplift
 * (150% by default) that the parties never agreed to. A workaround that bills the tenant a penalty
 * is not a workaround.
 *
 * The bay is the same question about the same tenant. Voyager assigns a rentable item to the
 * CUSTOMER RECORD, and a resident on month-to-month still has one and still parks; Atriom asked
 * `active|pending_approval`, so the answer changed overnight for a tenant nothing else about the
 * system considered gone.
 *
 * **What the fix must NOT do is lose the double-booking guard.** `active` used to carry it for
 * free — an active lease holds its own shop, and `LeaseCreationService` refuses a second active
 * lease on it. A lease past its term holds nothing: the sweep vacated the unit that morning, so
 * leasing may legitimately have re-let it while the renewal was being negotiated. Renewing then
 * puts two active leases on one shop, both billing, with `Unit::recomputeStatus()` reporting
 * `occupied` either way — nothing looks wrong. That guard is the tooth this file exists for.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'RNW']);
});

/**
 * A tenancy whose term ran out two months ago, in the state the BOX is actually in — projected by
 * the real `leases:expire` sweep rather than by a fixture writing `expired` directly, because the
 * sweep is the thing that creates this situation every morning.
 */
function endedTermLease(array $attrs = []): Lease
{
    $lease = makeLease(makeUnit(test()->asset, ['status' => 'occupied']), null, array_merge([
        'status' => 'active',
        'commencement_date' => CarbonImmutable::now()->subMonths(14)->startOfMonth()->toDateString(),
        'expiry_date' => CarbonImmutable::now()->subMonths(2)->endOfMonth()->toDateString(),
        'term_months' => 12,
        'base_rent_monthly' => 100000,
        'service_charge_monthly' => 10000,
    ], $attrs));

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => 100000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'start_date' => $lease->commencement_date,
        'is_active' => true,
    ]);

    return $lease->fresh();
}

/** The renewal terms an operator types into the modal. */
function agreedRenewalTerms(array $overrides = []): array
{
    return array_merge([
        'new_term_months' => 12,
        'new_rent' => 120000.0,
        'new_service_charge' => 12000.0,
    ], $overrides);
}

it('renews a lease whose term the nightly sweep has already closed', function () {
    $lease = endedTermLease();

    test()->artisan('leases:expire')->assertSuccessful();

    // The state the operator actually finds the lease in when the renewal comes back signed.
    expect($lease->fresh()->status)->toBe('expired');

    $endedOn = CarbonImmutable::parse($lease->expiry_date);

    $renewal = app(LeaseRenewalService::class)->renew($lease->fresh(), agreedRenewalTerms());

    expect($renewal->status)->toBe('active')
        ->and($renewal->previous_lease_id)->toBe($lease->id)
        // NO GAP IN THE TENANCY: the successor starts the day after the old term ended, which is
        // what the parties signed — not the day somebody got round to keying it in.
        ->and($renewal->commencement_date->toDateString())->toBe($endedOn->addDay()->toDateString())
        ->and((float) $renewal->base_rent_monthly)->toBe(120000.0)
        // …and the original is closed off as renewed, which is the write the immutability hook
        // refused: `expired` is terminal, so this needed its own shape-recognised carve-out.
        ->and($lease->fresh()->status)->toBe('renewed');
});

it('still renews an ordinary lease inside its term, and leaves it running', function () {
    // The control. A rule that refused everything would satisfy every refusal below, and this is
    // the path every renewal in the portfolio takes today.
    //
    // BOTH statuses changed on 2026-09-10 and the old pair was the money defect. This lease has
    // three months left to run: the successor is `future` because it has not started, and the
    // ORIGINAL stays `active` because it has not finished — it is still billing a shop that is
    // still trading. It used to be stamped `renewed` here, which is terminal and outside
    // `BILLABLE_STATUSES`, so signing the renewal silently stopped invoicing those three months.
    // `leases:expire` writes `renewed` on the day the term actually ends.
    // See `ALeaseSignedBeforeItStartsIsNotYetRunningTest`.
    $lease = endedTermLease([
        'expiry_date' => CarbonImmutable::now()->addMonths(3)->endOfMonth()->toDateString(),
    ]);

    $renewal = app(LeaseRenewalService::class)->renew($lease, agreedRenewalTerms());

    expect($renewal->status)->toBe('future')
        ->and($lease->fresh()->status)->toBe('active');
});

it('refuses to renew onto a shop that has since been re-let', function () {
    // THE tooth. The sweep vacated the unit at 05:15 and leasing signed somebody else two weeks
    // later — entirely legitimately, because as far as the system was concerned the shop was free.
    // Renewing now would put two active leases on one unit, both billing it every month.
    $lease = endedTermLease();

    test()->artisan('leases:expire')->assertSuccessful();

    $newTenancy = makeLease($lease->unit, null, [
        'status' => 'active',
        'commencement_date' => CarbonImmutable::now()->subWeeks(2)->toDateString(),
        'expiry_date' => CarbonImmutable::now()->addYear()->toDateString(),
    ]);

    // TWO deliberately redundant layers refuse this, and neither can be mutation-killed alone
    // because each covers for the other: `canBeRenewed()` answers it from a plain read (the
    // SEQUENTIAL case — the shop was re-let days ago), and the service re-asks it as a LOCKING
    // read inside the transaction (the RACE — a re-let committed while this renewal was in
    // flight, which a plain read inside the transaction is answered from before). Removing both
    // turns this red; the race layer itself is unexercised here by construction, because SQLite
    // compiles `lockForUpdate()` to nothing, and it is gated instead by
    // `ConcurrencyPolicy::AUTHORITATIVE_GUARDS`, which already registers
    // `Unit::isActivelyLeasedForUpdate`.
    //
    // The MESSAGE is asserted, not just the throw: the operator must be told the shop is let to
    // somebody else, not that "an expired lease cannot be renewed" — the fact they need is on the
    // unit, and a refusal naming the wrong record sends them to the wrong screen.
    expect(fn () => app(LeaseRenewalService::class)->renew($lease->fresh(), agreedRenewalTerms()))
        ->toThrow(InvalidArgumentException::class, $lease->unit->code);

    // Nothing half-written: no successor, and the original still reads as it did.
    expect(Lease::where('previous_lease_id', $lease->id)->count())->toBe(0)
        ->and($lease->fresh()->status)->toBe('expired')
        ->and($newTenancy->fresh()->status)->toBe('active');
});

it('refuses a tenancy somebody actually closed', function (string $status) {
    // The other control, and the half that must never move: `terminated`, `cancelled` and `renewed`
    // are each a person's act with a successor document of its own. Only `expired` is a machine's
    // guess about today, which is the whole reason it earns a carve-out and they do not.
    $lease = endedTermLease();
    $lease->forceFill(['status' => $status])->saveQuietly();

    expect(fn () => app(LeaseRenewalService::class)->renew($lease->fresh(), agreedRenewalTerms()))
        ->toThrow(InvalidArgumentException::class);

    expect(Lease::where('previous_lease_id', $lease->id)->count())->toBe(0);
})->with(['terminated', 'cancelled', 'renewed']);

it('refuses a tenancy that gave notice, because the sweep closes it rather than expiring it', function () {
    // The widening must not reach a tenant who is LEAVING, and the mechanism turned out to be one
    // layer further back than expected — which is worth pinning precisely rather than assuming.
    //
    // `terminate()` moves `expiry_date` ONTO the termination date and leaves the lease `active` so
    // it keeps billing until then; `leases:expire` then reads the lease\'s own termination EVENT
    // and closes it as `terminated`, NOT `expired`. So "expired with notice served" is a state the
    // sweep never produces, and this tenancy is refused because it is closed — the ordinary rule,
    // through the real path.
    //
    // Built with time travel deliberately: serving notice for a future date is the only way to
    // reach the under-notice branch, and a fixture that skips it leaves a FUTURE expiry, so every
    // assertion here would pass because the term had not ended. The first version of this case did
    // exactly that and stayed green with the guard deleted.
    $lease = endedTermLease([
        'expiry_date' => CarbonImmutable::now()->addMonths(6)->endOfMonth()->toDateString(),
    ]);

    test()->travelTo(CarbonImmutable::now()->subWeeks(6)->startOfDay());
    app(LeaseTerminationService::class)->terminate($lease->fresh(), [
        'termination_date' => CarbonImmutable::now()->addWeeks(2)->toDateString(),
        'reason' => 'Tenant served notice.',
    ]);
    test()->travelBack();

    // Under notice the lease stays live until the agreed day — so it is the sweep's own candidate.
    expect($lease->fresh()->status)->toBe('active');

    test()->artisan('leases:expire')->assertSuccessful();

    expect($lease->fresh()->status)->toBe('terminated')
        ->and($lease->fresh()->canBeRenewed())->toBeFalse()
        ->and(fn () => app(LeaseRenewalService::class)->renew($lease->fresh(), agreedRenewalTerms()))
        ->toThrow(InvalidArgumentException::class);
});

it('offers the Renew button on a lease the sweep has expired', function () {
    // The UI half. The button and the service read ONE predicate, so a lease the service would
    // renew can never be one the panel refuses to offer — which is exactly what it was doing.
    test()->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->actingAs(makeUser('super_admin'));

    $lease = endedTermLease();
    test()->artisan('leases:expire')->assertSuccessful();

    asTenant(test()->asset, function () use ($lease) {
        Livewire::test(EditLease::class, ['record' => $lease->fresh()->getRouteKey()])
            ->assertActionVisible('renew');
    });
});

it('refuses a bay to a tenancy whose term has ended, and says what to do instead', function () {
    // **Deliberately refused, and this is the half worth writing down** — widening it was tried,
    // measured and reverted on 2026-09-10.
    //
    // A bay is not like a lease. `rentable_items.status` is a PROJECTION whose stated meaning is
    // that a term ending RELEASES the space to the market, exactly as the same 05:15 sweep vacates
    // the unit — and `isHeldOn()`, the double-let guard, reads the same `active|pending_approval`
    // list. So a bay attached to an `expired` lease reads AVAILABLE the instant it is attached,
    // can be let to somebody else with no clash raised, and bills nothing. Three defects, not a
    // feature. It is also Yardi's answer: a Voyager *Past* lease acquires no rentable items.
    $lease = endedTermLease();
    test()->artisan('leases:expire')->assertSuccessful();

    $bay = RentableItem::create([
        'asset_id' => test()->asset->id,
        'code' => 'P-101',
        'type' => 'parking',
        'monthly_rate' => 500,
    ]);

    expect(fn () => app(AssignRentableItemService::class)->assign($lease->fresh(), $bay, []))
        ->toThrow(DomainException::class);

    expect($bay->fresh()->status)->toBe(RentableItem::STATUS_AVAILABLE)
        ->and($lease->fresh()->rentableItems()->count())->toBe(0);
});

it('takes the bay once the tenancy is continued, which is the path the refusal names', function () {
    // The control, and the operator's actual route: continuing a tenancy is an explicit act. Both
    // answers make the lease live again — this drives the holdover conversion, and the renewal
    // above is the other — after which the bay behaves normally and, unlike on an `expired` lease,
    // actually bills.
    test()->seed(ChartOfAccountsSeeder::class);
    test()->seed(AccountMappingSeeder::class);

    $lease = endedTermLease();
    test()->artisan('leases:expire')->assertSuccessful();

    app(ConvertLeaseToHoldoverService::class)->convert($lease->fresh(), [
        'rate_pct' => 150,
        'reason' => 'Tenant trading on while the renewal is negotiated.',
    ]);

    expect($lease->fresh()->status)->toBe('active');

    $bay = RentableItem::create([
        'asset_id' => test()->asset->id,
        'code' => 'P-103',
        'type' => 'parking',
        'monthly_rate' => 500,
    ]);

    app(AssignRentableItemService::class)->assign($lease->fresh(), $bay, ['monthly_rate' => 500]);

    expect($bay->fresh()->status)->toBe(RentableItem::STATUS_ASSIGNED)
        ->and($lease->fresh()->rentableItems()->wherePivotNull('effective_to')->count())->toBe(1);
});

it('carries only the bays the tenant still holds into the renewal', function () {
    // Found while widening renewal to an ended term, which is exactly when a bay is most likely to
    // have been given back. The carry loop read the WHOLE holding history and deliberately does not
    // copy `effective_to`, so every released bay was re-attached to the renewal open-endedly and
    // `rebuildCharge()` billed it again — on a document nobody re-reads item by item.
    $lease = endedTermLease();

    $kept = RentableItem::create([
        'asset_id' => test()->asset->id, 'code' => 'P-201', 'type' => 'parking', 'monthly_rate' => 500,
    ]);
    $givenBack = RentableItem::create([
        'asset_id' => test()->asset->id, 'code' => 'P-202', 'type' => 'parking', 'monthly_rate' => 700,
    ]);

    $assign = app(AssignRentableItemService::class);
    $assign->assign($lease->fresh(), $kept, ['monthly_rate' => 500]);
    $assign->assign($lease->fresh(), $givenBack, ['monthly_rate' => 700]);

    // Handed back mid-term, through the real release path.
    $assign->release($lease->fresh(), $givenBack, CarbonImmutable::parse($lease->expiry_date)->subMonths(6));

    $renewal = app(LeaseRenewalService::class)->renew($lease->fresh(), agreedRenewalTerms());

    $carried = $renewal->rentableItems()->wherePivotNull('effective_to')->pluck('rentable_items.id');

    expect($carried->all())->toBe([$kept->id]);
});

it('refuses when an ADDITIONAL unit of a multi-unit lease has been re-let', function () {
    // Found by review. The guard read `leases.unit_id` — the MASTER pointer — while the renewal
    // re-attaches the whole unit set through `syncUnits()`. A lease over two shops is vacated on
    // BOTH by the sweep, and leasing can then sign a new lease with the ADDITIONAL shop as its own
    // master, legitimately, because the creation guard asks whether that unit has an ACTIVE lease
    // and this one is `expired`. Renewing afterwards put two active leases on it, both billing,
    // and `totalAreaSqmForPeriod()` counted the shop twice in the CAM denominator — mis-charging
    // every other tenant in the pool while the pool still tied out.
    $lease = endedTermLease();
    $second = makeUnit(test()->asset, ['code' => 'A-02', 'status' => 'occupied']);
    $lease->syncUnits([$lease->unit_id, $second->id], $lease->unit_id);

    test()->artisan('leases:expire')->assertSuccessful();

    // Somebody else takes the ADDITIONAL shop, with it as their own master unit.
    makeLease($second, null, [
        'status' => 'active',
        'commencement_date' => CarbonImmutable::now()->subWeeks(2)->toDateString(),
        'expiry_date' => CarbonImmutable::now()->addYear()->toDateString(),
    ]);

    // BOTH layers asserted, because each covers for the other: the shared predicate (which is also
    // what hides the Renew button and keeps the holdover card honest) and the locking guard inside
    // the transaction. Asserting only the throw would let the predicate rot silently.
    expect($lease->fresh()->canBeRenewed())->toBeFalse()
        ->and($lease->fresh()->unitLetToSomebodyElse()?->code)->toBe('A-02')
        ->and(fn () => app(LeaseRenewalService::class)->renew($lease->fresh(), agreedRenewalTerms()))
        ->toThrow(InvalidArgumentException::class, 'A-02');

    expect(Lease::where('previous_lease_id', $lease->id)->count())->toBe(0);
});

it('refuses when a bay has been let to another tenant since the term ended', function () {
    // Found by review. The carry loop `attach()`es directly, so none of `AssignRentableItemService`
    // runs — including `isHeldOn()`, the only double-let guard a bay has. The sweep frees the bay
    // the morning the term ends, an operator lets it to somebody else a fortnight later, and the
    // renewal re-attached it open-endedly from the day after the old expiry: two live holdings on
    // one bay, and the pivot is keyed on (holder, item, effective_from) so nothing catches it.
    $lease = endedTermLease();

    $bay = RentableItem::create([
        'asset_id' => test()->asset->id, 'code' => 'P-301', 'type' => 'parking', 'monthly_rate' => 500,
    ]);
    app(AssignRentableItemService::class)->assign($lease->fresh(), $bay, ['monthly_rate' => 500]);

    test()->artisan('leases:expire')->assertSuccessful();

    // The bay reads free once the term ends — that is the projection working as designed — so
    // letting it to the next tenant is a legitimate act, not operator error.
    $nextTenant = makeLease(makeUnit(test()->asset), null, ['status' => 'active']);
    app(AssignRentableItemService::class)->assign($nextTenant, $bay->fresh(), ['monthly_rate' => 600]);

    expect(fn () => app(LeaseRenewalService::class)->renew($lease->fresh(), agreedRenewalTerms()))
        ->toThrow(InvalidArgumentException::class, 'P-301');

    // No renewal was written at all, so no THIRD holding joined the two that legitimately exist:
    // the new tenant's, and the old lease's own — which nothing closes when a term ends, because
    // the projection frees the bay's STATUS and deliberately leaves the history row alone.
    expect(Lease::where('previous_lease_id', $lease->id)->count())->toBe(0)
        ->and(DB::table('rentable_item_holdings')
            ->where('rentable_item_id', $bay->id)
            ->whereNull('effective_to')
            ->count())->toBe(2)
        ->and($nextTenant->fresh()->rentableItems()->wherePivotNull('effective_to')->count())->toBe(1);
});

it('carries a bay that was live when an EARLY renewal commenced', function () {
    // Found by review. `commencement_date` is an unbounded picker and the service honours it, so
    // an early re-gear — a new term starting BEFORE the old one ends — is ordinary retail
    // practice. Bounding the carry at the old expiry dropped a bay that was still held on the day
    // the new term began. The bound is the EARLIER of the two.
    $lease = endedTermLease([
        'expiry_date' => CarbonImmutable::now()->addMonths(3)->endOfMonth()->toDateString(),
    ]);

    $bay = RentableItem::create([
        'asset_id' => test()->asset->id, 'code' => 'P-401', 'type' => 'parking', 'monthly_rate' => 500,
    ]);
    $assign = app(AssignRentableItemService::class);
    $assign->assign($lease->fresh(), $bay, ['monthly_rate' => 500]);
    // Given back a month from now — after the early renewal starts, before the old term ends.
    $assign->release($lease->fresh(), $bay, CarbonImmutable::now()->addMonth());

    $renewal = app(LeaseRenewalService::class)->renew($lease->fresh(), agreedRenewalTerms([
        'commencement_date' => CarbonImmutable::now()->toDateString(),
    ]));

    expect($renewal->rentableItems()->wherePivotNull('effective_to')->pluck('rentable_items.id')->all())
        ->toBe([$bay->id]);
});

it('does not let an IMPORT row walk through the renewal carve-out', function () {
    // Found by review. The carve-out recognises `expired` -> `renewed` by shape, and the docblock
    // defended that on the panel being closed — which it is. `LeaseImporter` is not a panel: it
    // accepts `status: renewed` and `firstOrNew(['reference' => …])` UPDATES an existing lease, so
    // one CSV row would have rewritten the rent on a lease the system calls immutable and marked
    // it renewed with no successor at all. The shape now also requires a successor to exist, which
    // an import row cannot fabricate.
    $lease = endedTermLease();
    test()->artisan('leases:expire')->assertSuccessful();

    expect(fn () => $lease->fresh()->fill([
        'status' => 'renewed',
        'base_rent_monthly' => 1,
    ])->save())->toThrow(DomainException::class);

    expect((float) $lease->fresh()->base_rent_monthly)->toBe(100000.0)
        ->and($lease->fresh()->status)->toBe('expired');
});

it('does not tell a converted holdover that its months are unbilled', function () {
    // Found by review. A converted holdover's expiry is ALWAYS past — that is what makes it a
    // holdover — so a branch keyed purely on the date told the operator the elapsed months were
    // unbilled. They have already been invoiced at the uplift, and saying otherwise invites a
    // second invoice for the same period.
    test()->seed(ChartOfAccountsSeeder::class);
    test()->seed(AccountMappingSeeder::class);
    test()->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->actingAs(makeUser('super_admin'));

    $lease = endedTermLease();
    test()->artisan('leases:expire')->assertSuccessful();

    app(ConvertLeaseToHoldoverService::class)->convert($lease->fresh(), [
        'rate_pct' => 150,
        'reason' => 'Trading on while the renewal is negotiated.',
    ]);

    $lease = $lease->fresh();
    expect($lease->isConvertedHoldover())->toBeTrue();

    asTenant(test()->asset, function () use ($lease) {
        $description = Livewire::test(EditLease::class, ['record' => $lease->getRouteKey()])
            ->instance()
            ->getAction('renew')
            ->getModalDescription();

        expect((string) $description)->toBe(__('admin.actions.renew_modal_description', [
            'ends' => $lease->expiry_date->format('d/m/Y'),
        ]));
    });
});

it('words every refusal it can raise in both languages', function () {
    // A refusal is the app talking to a person: the renewal action catches these and renders them
    // as a toast. `fallback: false` deliberately — `Lang::has()` falls back to English, so the
    // obvious check passes for a key that exists only in the English catalogue.
    foreach ([
        'admin.refusals.lease_not_renewable',
        'admin.refusals.lease_changed_while_renewing',
        'admin.refusals.lease_unit_already_relet',
        'admin.actions.renew_modal_description_ended',
        'admin.errors.rentable_item_lease_not_active',
        'admin.refusals.rentable_item_relet_since_term_ended',
    ] as $key) {
        expect(Lang::has($key, 'en', false))->toBeTrue($key)
            ->and(Lang::has($key, 'ar', false))->toBeTrue($key)
            // …and the Arabic is Arabic, not an English sentence sitting in the right key — the
            // realistic failure when keys are added in one pass and reviewed in English.
            ->and((bool) preg_match('/\p{Arabic}/u', __($key, [], 'ar')))->toBeTrue($key);
    }
});
