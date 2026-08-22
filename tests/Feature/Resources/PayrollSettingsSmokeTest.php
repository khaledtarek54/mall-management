<?php

use App\Filament\Admin\Pages\Settings;
use App\Settings\PayrollSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('renders the settings page with the payroll tab and saves its policy', function () {
    // The three statutory RATES left this tab on 2026-08-22 (EG-03) — they are dated rungs at
    // /admin/payroll-rates now, because a setting cannot carry the day a rate came into force.
    // What is left here is policy, and the tab must still render and save it.
    $this->actingAs(makeUser('super_admin'));

    Livewire::test(Settings::class)
        ->assertOk()
        ->set('data.payroll.gratuity_enabled', true)
        ->set('data.payroll.gratuity_days_first_five', 15)
        ->set('data.payroll.gratuity_days_thereafter', 30)
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(PayrollSettings::class);
    expect($settings->gratuity_enabled)->toBeTrue();
    expect((float) $settings->gratuity_days_first_five)->toBe(15.0);
    expect((float) $settings->gratuity_days_thereafter)->toBe(30.0);
});
