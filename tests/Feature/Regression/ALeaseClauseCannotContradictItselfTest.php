<?php

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Models\Lease;
use App\Services\LeaseRenewalService;
use App\Services\RentEscalationService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Lang;
use Spatie\Permission\PermissionRegistrar;

/**
 * A bound that can never be reached is not a bound — it is a contradiction, and it is refused.
 *
 * Two cards from the tester's board (kZ77DQa7, H22OkiFa), both High, and they are ONE rule about
 * three pairs of fields on the lease. Whichever bound is applied LAST silently wins, so the figure
 * the operator typed becomes the one thing that can never happen — and the form goes on displaying
 * it. The escalation collar had this guarded since it shipped; the other two did not.
 *
 * **The first card's premise is FALSE and the truth is worse.** It reports that Minimum/Maximum
 * increase "have no functional purpose" when Escalation Type is Fixed %. `RentEscalationService::
 * collar()` deliberately applies to whatever rate is about to be used — its docblock says so, and
 * calls the ceiling "a real rail against a mistyped rate" on exactly a fixed clause. So on the
 * tester's own lease, a stated 10% under a floor of 30% escalates the rent by **thirty percent**,
 * every year, while the Annual Escalation field reads 10.
 *
 * On a CPI clause a collar that brackets the index IS the clause — *"the greater of CPI or 3%,
 * capped at 10%"* — so only `fixed_percent` is asked about, where the rate is stated and constant
 * and a bound that moves it is a contradiction rather than a rail. The ceiling still catches a
 * mistyped rate; it now says so instead of quietly applying a different number.
 *
 * **The second card is the same sentence about money.** `LateFeeService` applies `max($min, …)` and
 * THEN `min($fee, $max)`, so a minimum of 1,000 under a cap of 100 charges 100 — the tester's own
 * words, *"no single fee value can satisfy both rules"*.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'CLS']);
    $this->unit = makeUnit($this->asset);
});

function leaseWith(array $attrs): Lease
{
    return makeLease(test()->unit, null, array_merge(['status' => 'draft'], $attrs));
}

it('leaves a fixed rate outside its collar LEGAL, and clamps it', function () {
    // **The refusal that was tried and reverted.** Trello kZ77DQa7 reports the collar as
    // purposeless on a Fixed % clause; it is the opposite, and refusing the combination broke five
    // existing regression cases — including `EscalationCollarTest`'s own control, "equal bounds are
    // a fixed step, not a contradiction" — plus the pre-staging QA harness and the renewal path.
    // The clamp IS the semantic; what was missing is that nobody could see it, which the rate
    // field's hint now says.
    $inside = leaseWith([
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 7,
        'escalation_floor_rate' => 3,
        'escalation_ceiling_rate' => 10,
    ]);

    // The tester's own numbers: legal, and the rent really does step 30.
    $clamped = leaseWith([
        'escalation_type' => 'fixed_percent',
        'escalation_rate' => 10,
        'escalation_floor_rate' => 30,
        'escalation_ceiling_rate' => 30,
    ]);

    expect($inside->exists)->toBeTrue()
        ->and(RentEscalationService::collar($inside, 7.0))->toBe(7.0)
        ->and($clamped->exists)->toBeTrue()
        ->and(RentEscalationService::collar($clamped, 10.0))->toBe(30.0);
});

it('leaves a CPI clause free to be bracketed by its collar', function () {
    // The case the collar exists for: "the greater of CPI or 3%, capped at 10%". The index is not
    // known at signing, so the bounds are the whole clause and must not be compared with anything.
    $lease = leaseWith([
        'escalation_type' => 'cpi',
        'escalation_rate' => 0,
        'escalation_floor_rate' => 3,
        'escalation_ceiling_rate' => 10,
        'escalation_index_base_value' => 100,
    ]);

    expect($lease->exists)->toBeTrue()
        ->and(RentEscalationService::collar($lease, 1.0))->toBe(3.0)
        ->and(RentEscalationService::collar($lease, 25.0))->toBe(10.0);
});

it('still refuses a collar that is inverted in itself', function () {
    // The rule that was already guarded, asserted so the rewrite did not drop it.
    expect(fn () => leaseWith([
        'escalation_type' => 'cpi',
        'escalation_floor_rate' => 20,
        'escalation_ceiling_rate' => 5,
        'escalation_index_base_value' => 100,
    ]))->toThrow(DomainException::class);
});

it('refuses a minimum late fee above the cap — the tester s numbers', function () {
    // Their own screen: lease 21, edited. `LateFeeService` applies `max($min, …)` and then
    // `min($fee, $max)`, so 1,000 under a cap of 100 charges 100 and the minimum is the one amount
    // that can never be charged.
    $lease = leaseWith(['late_fee_minimum' => 50, 'late_fee_maximum' => 500]);

    expect(fn () => $lease->update(['late_fee_minimum' => 1000, 'late_fee_maximum' => 100]))
        ->toThrow(DomainException::class);

    expect((float) $lease->fresh()->late_fee_minimum)->toBe(50.0);
});

it('does not refuse it on CREATE, which is deliberate and narrow', function () {
    // The create doors are the lease FORM — which carries its own inline rule, so the operator is
    // told at the field — and the services that COPY an existing clause, which must not be blocked
    // (see the renewal case below). `LeaseImporter` carries no late-fee columns at all, so an
    // import cannot reach this either way. Asserted so the exemption is a decision on the record
    // rather than something a later reader mistakes for an oversight.
    $lease = leaseWith(['late_fee_minimum' => 1000, 'late_fee_maximum' => 100]);

    expect($lease->exists)->toBeTrue();
});

it('treats a cap of ZERO as no cap, not as the smallest possible one', function () {
    // The control that decides whether the rule is usable: 0 means NO CAP at every tier — what
    // every install had before the column existed — so it can never be the smaller bound. A plain
    // `gte` rule would have refused the commonest value on the form.
    // Through an UPDATE, because that is where the guard bites — a create is deliberately exempt.
    $lease = leaseWith(['late_fee_minimum' => 50, 'late_fee_maximum' => 500]);

    $lease->update(['late_fee_minimum' => 500, 'late_fee_maximum' => 0]);

    expect((float) $lease->fresh()->late_fee_minimum)->toBe(500.0)
        ->and((float) $lease->fresh()->lateFeeTerms()['maximum'])->toBe(0.0);
});

it('accepts a minimum under its cap', function () {
    $lease = leaseWith(['late_fee_minimum' => 50, 'late_fee_maximum' => 500]);

    expect($lease->exists)->toBeTrue();
});

it('leaves a lease that ALREADY carries the contradiction editable', function () {
    // The over-lock control, and staging is the reason it exists: the lease these two cards were
    // filed from carries rate 10 under a floor of 30 right now. Refusing every save would make it
    // unsaveable for any reason at all — including the edit that fixes it — which is the trap
    // `#[NeverDeletable]` and the bank-account chart rule both record. The clause is checked only
    // when the operator is TOUCHING it.
    $lease = leaseWith(['escalation_type' => 'fixed_percent', 'escalation_rate' => 10]);
    $lease->forceFill(['escalation_floor_rate' => 30, 'escalation_ceiling_rate' => 30])->saveQuietly();

    $lease->fresh()->update(['notes' => 'an unrelated edit']);
    expect($lease->fresh()->notes)->toBe('an unrelated edit');

    // …and the remedy itself goes through.
    $lease->fresh()->update(['escalation_floor_rate' => 5, 'escalation_ceiling_rate' => 15]);
    expect((float) $lease->fresh()->escalation_floor_rate)->toBe(5.0);
});

it('shows on the form the step a collared fixed rate will really take', function () {
    // The card's actual fix. The collar clamps the rate — documented and tested — and the form
    // showed the stated figure with nothing to say the rent would move by a different one.
    test()->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->actingAs(makeUser('super_admin'));

    asTenant(test()->asset, function () {
        Livewire\Livewire::test(CreateLease::class)
            ->fillForm([
                'escalation_type' => 'fixed_percent',
                'escalation_rate' => 10,
                'escalation_floor_rate' => 30,
                'escalation_ceiling_rate' => 30,
            ])
            ->assertSee(__('admin.helpers.escalation_rate_collared', ['applied' => '30']));
    });
});

it('says nothing when the rate already sits inside its collar', function () {
    // The control: a note shown on a clause that behaves as written is noise, and gets read as an
    // error on the form where it matters.
    test()->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->actingAs(makeUser('super_admin'));

    asTenant(test()->asset, function () {
        Livewire\Livewire::test(CreateLease::class)
            ->fillForm([
                'escalation_type' => 'fixed_percent',
                'escalation_rate' => 7,
                'escalation_floor_rate' => 3,
                'escalation_ceiling_rate' => 10,
            ])
            ->assertDontSee(__('admin.helpers.escalation_rate_collared', ['applied' => '7']));
    });
});

it('can still RENEW a lease that carries the late-fee contradiction', function () {
    // Found by review. `LeaseRenewalService` rebuilds every fillable column, so on a renewal all of
    // them are dirty — and a lease already carrying the contradiction (staging has one) could not
    // be renewed at all, refused with a message about late fees. The guard asks `exists`, so a
    // CREATE never trips it; the create doors are the form, which carries its own inline rule, and
    // the services that copy an existing clause.
    $lease = leaseWith(['status' => 'active', 'late_fee_percent' => 2]);
    $lease->forceFill(['late_fee_minimum' => 1000, 'late_fee_maximum' => 100])->saveQuietly();

    $renewal = app(LeaseRenewalService::class)->renew($lease->fresh(), [
        'new_term_months' => 12,
        'new_rent' => 120000,
    ]);

    expect($renewal->exists)->toBeTrue()
        ->and((float) $renewal->late_fee_minimum)->toBe(1000.0);
});

it('words the refusal and the collar notes in EN and AR', function () {
    foreach ([
        'admin.errors.late_fee_minimum_above_cap',
        'admin.helpers.escalation_rate_collared',
        'admin.helpers.escalation_collar_on_fixed',
    ] as $key) {
        expect(Lang::has($key, 'en', false))->toBeTrue($key)
            ->and(Lang::has($key, 'ar', false))->toBeTrue($key)
            ->and((bool) preg_match('/\p{Arabic}/u', __($key, [], 'ar')))->toBeTrue($key);
    }
});
