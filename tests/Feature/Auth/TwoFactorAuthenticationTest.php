<?php

use App\Models\User;

it('the User model has 2FA columns on its underlying table', function () {
    expect(\Schema::hasColumn('users', 'two_factor_secret'))->toBeTrue();
    expect(\Schema::hasColumn('users', 'two_factor_recovery_codes'))->toBeTrue();
    expect(\Schema::hasColumn('users', 'two_factor_confirmed_at'))->toBeTrue();
});

it('the User model uses the TwoFactorAuthenticatable trait', function () {
    expect(class_uses_recursive(User::class))
        ->toContain(\Stephenjude\FilamentTwoFactorAuthentication\TwoFactorAuthenticatable::class);
});

it('the admin panel registers the TwoFactorAuthenticationPlugin', function () {
    $plugin = \Filament\Facades\Filament::getPanel('admin')->getPlugin('filament-two-factor-authentication');

    expect($plugin)->toBeInstanceOf(\Stephenjude\FilamentTwoFactorAuthentication\TwoFactorAuthenticationPlugin::class);
    expect($plugin->hasEnabledTwoFactorAuthentication())->toBeTrue();
});
