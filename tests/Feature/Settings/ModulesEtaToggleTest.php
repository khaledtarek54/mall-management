<?php

use App\Filament\Admin\Widgets\EtaCompliance;
use App\Settings\ModulesSettings;
use App\Support\Modules;
use Database\Seeders\RolesPermissionsSeeder;

it('Modules::enabled returns the configured value for the eta key', function () {
    $settings = app(ModulesSettings::class);
    $settings->eta = true;
    $settings->save();

    expect(Modules::enabled('eta'))->toBeTrue();

    $settings->eta = false;
    $settings->save();

    expect(Modules::enabled('eta'))->toBeFalse();
});

it('EtaCompliance widget hides when the eta module is disabled', function () {
    // Accounting, not manager: ETA e-invoicing compliance is an accounting concern, and
    // App\Support\DashboardLayout puts this widget on the accounting dashboard only.
    // `seedRoles()` (which makeUser calls) only creates the six original roles, so the real
    // seeder is what makes `accounting` exist at all.
    $this->seed(RolesPermissionsSeeder::class);
    $user = makeUser('accounting');
    $this->actingAs($user);

    $settings = app(ModulesSettings::class);
    $settings->eta = true;
    $settings->save();

    expect(EtaCompliance::canView())->toBeTrue();

    $settings->eta = false;
    $settings->save();

    expect(EtaCompliance::canView())->toBeFalse();
});

it('eta appears in the Modules::KEYS canonical list', function () {
    expect(Modules::KEYS)->toContain('eta');
});
