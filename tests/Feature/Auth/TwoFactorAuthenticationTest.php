<?php

use App\Models\User;
use Filament\Facades\Filament;
use Stephenjude\FilamentTwoFactorAuthentication\TwoFactorAuthenticatable;
use Stephenjude\FilamentTwoFactorAuthentication\TwoFactorAuthenticationPlugin;

it('the User model has 2FA columns on its underlying table', function () {
    expect(Schema::hasColumn('users', 'two_factor_secret'))->toBeTrue();
    expect(Schema::hasColumn('users', 'two_factor_recovery_codes'))->toBeTrue();
    expect(Schema::hasColumn('users', 'two_factor_confirmed_at'))->toBeTrue();
});

it('the User model uses the TwoFactorAuthenticatable trait', function () {
    expect(class_uses_recursive(User::class))
        ->toContain(TwoFactorAuthenticatable::class);
});

it('the admin panel registers the TwoFactorAuthenticationPlugin', function () {
    $plugin = Filament::getPanel('admin')->getPlugin('filament-two-factor-authentication');

    expect($plugin)->toBeInstanceOf(TwoFactorAuthenticationPlugin::class);
    expect($plugin->hasEnabledTwoFactorAuthentication())->toBeTrue();
});
