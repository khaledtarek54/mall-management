<?php

use App\Filament\Admin\Pages\Settings;
use App\Settings\PayrollSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('renders the settings page with the payroll tab and saves the rates', function () {
    $this->actingAs(makeUser('super_admin'));

    Livewire::test(Settings::class)
        ->assertOk()
        ->set('data.payroll.social_insurance_rate', 11)
        ->set('data.payroll.salary_tax_rate', 3)
        ->set('data.payroll.employer_social_insurance_rate', 18.75)
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(PayrollSettings::class);
    expect((float) $settings->social_insurance_rate)->toBe(11.0);
    expect((float) $settings->salary_tax_rate)->toBe(3.0);
    expect((float) $settings->employer_social_insurance_rate)->toBe(18.75);
});
