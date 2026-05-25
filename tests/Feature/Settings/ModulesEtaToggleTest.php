<?php

use App\Filament\Admin\Widgets\EtaCompliance;
use App\Settings\ModulesSettings;
use App\Support\Modules;

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
    $user = makeUser('manager');
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
