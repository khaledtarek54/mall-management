<?php

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Lease;
use App\Services\ChargeScheduleService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\LeaseLadder;

/**
 * A DRAFT lease's rent follows its units — and so does its schedule (2026-09-11, from the review
 * of the term-edit fix).
 *
 * `EditLease::afterSave()` refuses a unit change on a LIVE lease and routes it to the space-change
 * act, which re-rates at an effective date. A draft is outside that list, so the form let its units
 * change and `syncUnits()` attached them and nothing else: on a rate-priced draft the rent is
 * rate × area, and the draft kept the rent of the old space — in its column AND in the seeded row
 * the wizard had written. Nothing has happened to a draft, so the wizard's own post-attach sequence
 * runs again: `repriceFromPremises()` moves the column and `repriceSeededRent()` amends the seeded
 * rows in place, re-syncs the levy and re-projects the steps from the new base. Flat-priced drafts
 * are untouched (a negotiated sum is not a function of area), and a draft whose rent has already
 * stepped is treated as live, for the reason a stepped lease cannot move its commencement.
 *
 * THE REVIEW'S FINDING, and the tooth this file lacked: the units picker carried TWO `disabled()`
 * calls and the later — `$operation === 'edit'` — won, so every Edit page was locked and the
 * "draft door" existed only in the Livewire harness, which fills a disabled field regardless.
 * `Lease::premisesLockedBecause()` is the ONE predicate now (`live` · `stepped` · `schedule`),
 * read by the field, its helper and the refusal — and the first case asserts the field is
 * ENABLED on a draft through the real page, which is the assertion nothing had made.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset(['code' => 'DRF']);
    $this->travelTo(CarbonImmutable::parse('2026-09-10'));
});

/** A rate-priced draft on a 100 m² shop at 6,000/m²/year: 50,000 a month, 10 % yearly. */
function ratePricedDraft(object $ctx, string $status = 'draft', string $basis = Lease::RENT_RATE): Lease
{
    $unit = makeUnit($ctx->asset, ['code' => 'D-'.uniqid(), 'status' => 'vacant', 'area_sqm' => 100]);
    $tenant = makeTenant();

    Livewire::test(CreateLease::class)->fillForm([
        'unit_id' => $unit->id, 'tenant_id' => $tenant->id, 'status' => $status,
        'commencement_date' => '2026-10-01', 'term_months' => 36, 'expiry_date' => '2029-09-30',
        'rent_pricing_basis' => $basis, 'base_rent_rate_per_sqm_year' => 6000,
        'base_rent_monthly' => 50000, 'service_charge_monthly' => 2500,
        'has_marketing_levy' => true, 'marketing_levy_rate' => 5,
        'escalation_type' => 'fixed_percent', 'escalation_rate' => 10,
        'escalation_interval_months' => null, 'security_deposit_months' => 3,
    ])->call('create')->assertHasNoFormErrors();

    return Lease::where('tenant_id', $tenant->id)->sole();
}

it('re-prices a rate-priced draft and its schedule when a unit is added', function () {
    asTenant($this->asset, function () {
        $lease = ratePricedDraft($this);
        expect((float) $lease->base_rent_monthly)->toBe(50000.0)
            ->and(LeaseLadder::rungs($lease, 'base_rent'))->toBe('50000@2026-10 55000@2027-10 60500@2028-10')
            ->and($lease->premisesLockedBecause())->toBeNull();

        // The door EXISTS in a browser: the picker is enabled on a draft's real Edit page.
        Livewire::test(EditLease::class, ['record' => $lease->getKey()])
            ->assertFormFieldEnabled('additional_unit_ids');

        $extra = makeUnit($this->asset, ['code' => 'D-X'.uniqid(), 'status' => 'vacant', 'area_sqm' => 50]);
        LeaseLadder::edit($lease, ['additional_unit_ids' => [$extra->id]]);

        // 6,000 × 150 m² ÷ 12 = 75,000 — the column, the seeded row IN PLACE (same row, same
        // start), the levy at 5 % of it, and the steps compounding from it.
        expect((float) $lease->fresh()->base_rent_monthly)->toBe(75000.0)
            ->and(LeaseLadder::rungsByDay($lease, 'base_rent'))->toBe('75000@2026-10-01..2027-09-30 82500@2027-10-01..2028-09-30 90750@2028-10-01..open')
            ->and(LeaseLadder::rungs($lease, 'marketing'))->toBe('3750@2026-10 4125@2027-10 4538@2028-10')
            ->and($lease->charges()->where('type', 'base_rent')->where('origin', 'seed')->where('is_active', true)->count())->toBe(1);
    });
});

it('leaves a flat-priced draft alone — a negotiated sum is not a function of area', function () {
    asTenant($this->asset, function () {
        $lease = ratePricedDraft($this, basis: Lease::RENT_FLAT);
        $before = LeaseLadder::rungsByDay($lease, 'base_rent');
        $ids = $lease->charges()->where('is_active', true)->orderBy('id')->pluck('id')->all();

        $extra = makeUnit($this->asset, ['code' => 'D-F'.uniqid(), 'status' => 'vacant', 'area_sqm' => 50]);
        LeaseLadder::edit($lease, ['additional_unit_ids' => [$extra->id]]);

        expect((float) $lease->fresh()->base_rent_monthly)->toBe(50000.0)
            ->and(LeaseLadder::rungsByDay($lease, 'base_rent'))->toBe($before)
            // Same rows, same ids — a re-price that finds nothing to move re-mints nothing.
            ->and($lease->charges()->where('is_active', true)->orderBy('id')->pluck('id')->all())->toBe($ids)
            ->and($lease->fresh()->units()->count())->toBe(2);
    });
});

it('still refuses the change on a live lease, on a draft whose rent has stepped, and on one carrying an act s row', function () {
    asTenant($this->asset, function () {
        // LIVE: the field is disabled and the payload is refused — the act owns the space.
        $live = ratePricedDraft($this, status: 'active');
        $extra = makeUnit($this->asset, ['code' => 'D-L'.uniqid(), 'status' => 'vacant', 'area_sqm' => 50]);
        expect($live->premisesLockedBecause())->toBe('live');
        Livewire::test(EditLease::class, ['record' => $live->getKey()])->assertFormFieldDisabled('additional_unit_ids');
        expect(fn () => Livewire::test(EditLease::class, ['record' => $live->getKey()])
            ->fillForm(['additional_unit_ids' => [$extra->id]])->call('save'))
            ->toThrow(DomainException::class, __('admin.fields.additional_units_locked'));

        // STEPPED: a draft whose first rung has started, in the draft's own words.
        $draft = ratePricedDraft($this);
        $this->travelTo(CarbonImmutable::parse('2027-10-05'));
        $extra2 = makeUnit($this->asset, ['code' => 'D-S'.uniqid(), 'status' => 'vacant', 'area_sqm' => 50]);
        expect($draft->fresh()->premisesLockedBecause())->toBe('stepped');
        Livewire::test(EditLease::class, ['record' => $draft->getKey()])
            ->assertFormFieldDisabled('additional_unit_ids')
            ->assertSee(__('admin.fields.additional_units_locked_draft'));
        expect(fn () => Livewire::test(EditLease::class, ['record' => $draft->getKey()])
            ->fillForm(['additional_unit_ids' => [$extra2->id]])->call('save'))
            ->toThrow(DomainException::class, __('admin.fields.additional_units_locked_draft'))
            ->and((float) $draft->fresh()->base_rent_monthly)->toBe(50000.0);

        // SCHEDULE: a draft carrying a row an import wrote (a manual base_rent row) — re-pricing
        // the seeded rows would amend and relabel the operator's own row.
        $this->travelTo(CarbonImmutable::parse('2026-09-10'));
        $imported = ratePricedDraft($this);
        app(ChargeScheduleService::class)->setAmount($imported, 'base_rent', 52000, CarbonImmutable::parse('2027-01-01'), ['name' => 'Base Rent']);
        expect($imported->fresh()->premisesLockedBecause())->toBe('schedule');
        Livewire::test(EditLease::class, ['record' => $imported->getKey()])->assertFormFieldDisabled('additional_unit_ids');
    });
});
