<?php

use App\Filament\Admin\Pages\Settings;
use App\Models\Charge;
use App\Models\Lease;
use App\Services\ConvertLeaseToHoldoverService;
use App\Settings\BillingSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * A default the modal refuses is not a default (SW-103).
 *
 * One number was stated three times and the three disagreed. Measured at HEAD on 2026-09-03: the
 * Convert-to-holdover modal floored `rate_pct` at 100 under a comment explaining why ("a rate below
 * it would price overstaying BELOW renewing"); `ConvertLeaseToHoldoverService` refused only "greater
 * than zero"; and the PORTFOLIO DEFAULT that modal prefills itself from — `billing.holdover_default
 * _rate_pct` on /admin/settings, the only tier there is, since `PropertySettings` has no holdover
 * key — floored at 0. So an operator could save 80, press the button, and be refused on a field they
 * had never touched, quoting a minimum the settings screen had accepted below moments earlier.
 *
 * `Lease::HOLDOVER_MIN_RATE_PCT` is the one number all three now read.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    // A lease whose term ran out months ago and which nobody has dealt with. Month END deliberately:
    // holdover starts the day after the term and the service refuses a start inside a month the term
    // already covered.
    $this->lease = makeLease(makeUnit($this->asset), null, [
        'status' => 'active',
        'start_date' => '2024-01-01',
        'expiry_date' => CarbonImmutable::now()->subMonths(3)->endOfMonth()->toDateString(),
        'base_rent_monthly' => 100000,
        'holdover_rate_pct' => 150,
    ]);

    // The service holds over from the base-rent SCHEDULE, not from the lease column.
    Charge::create([
        'lease_id' => $this->lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'origin' => Charge::ORIGIN_SEED, 'amount' => 100000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'start_date' => $this->lease->start_date, 'is_active' => true,
    ]);
});

it('refuses a portfolio default the conversion would refuse', function () {
    Livewire::test(Settings::class)
        ->set('data.billing.holdover_default_rate_pct', 80)
        ->call('save')
        ->assertHasErrors('data.billing.holdover_default_rate_pct');

    // The control beside the refusal: a legitimate figure still saves, so the assertion above is not
    // passing because the whole screen is broken.
    Livewire::test(Settings::class)
        ->set('data.billing.holdover_default_rate_pct', 150)
        ->call('save')
        ->assertHasNoErrors();

    expect((float) app(BillingSettings::class)->holdover_default_rate_pct)->toBe(150.0);
});

it('takes the settings floor and the modal floor from the same number', function () {
    expect(Lease::HOLDOVER_MIN_RATE_PCT)->toEqual(100.0);

    // Just below the constant is refused, exactly it is accepted — so the box really reads the
    // constant rather than carrying its own literal.
    Livewire::test(Settings::class)
        ->set('data.billing.holdover_default_rate_pct', Lease::HOLDOVER_MIN_RATE_PCT - 1)
        ->call('save')
        ->assertHasErrors('data.billing.holdover_default_rate_pct');

    Livewire::test(Settings::class)
        ->set('data.billing.holdover_default_rate_pct', Lease::HOLDOVER_MIN_RATE_PCT)
        ->call('save')
        ->assertHasNoErrors();
});

it('refuses a conversion below the floor and says how to get out of it', function () {
    try {
        app(ConvertLeaseToHoldoverService::class)->convert($this->lease->fresh(), [
            'rate_pct' => 80,
            'reason' => 'Winding down.',
        ]);

        $this->fail('a holdover priced below the last rent must be refused');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toBe(__('admin.refusals.holdover_rate_below_floor', ['min' => 100]))
            // The escape is part of the refusal: a rule with no way out is worse than the bug.
            ->and($e->getMessage())->toContain('100');
    }

    expect($this->lease->fresh()->holdover_from)->toBeNull();

    // The control: at the floor it converts, and the rent is simply unchanged — 100% is a real,
    // permitted arrangement, not a rejection boundary.
    app(ConvertLeaseToHoldoverService::class)->convert($this->lease->fresh(), [
        'rate_pct' => Lease::HOLDOVER_MIN_RATE_PCT,
        'reason' => 'Trading on while terms are agreed.',
    ]);

    $held = $this->lease->fresh();

    expect($held->holdover_from)->not->toBeNull()
        ->and((float) $held->base_rent_monthly)->toEqual(100000.0);
});

it('never proposes a default the conversion cannot accept', function () {
    // The shipped default, and the modal's own fallback path: `convert()` with no `rate_pct` reads
    // `BillingSettings` directly, which is the one route the modal's `minValue` cannot police.
    expect((float) app(BillingSettings::class)->holdover_default_rate_pct)
        ->toBeGreaterThanOrEqual(Lease::HOLDOVER_MIN_RATE_PCT);

    app(ConvertLeaseToHoldoverService::class)->convert($this->lease->fresh(), ['reason' => 'Still trading.']);

    $held = $this->lease->fresh();

    expect($held->holdover_from)->not->toBeNull()
        ->and((float) $held->base_rent_monthly)->toEqual(150000.0);
});
