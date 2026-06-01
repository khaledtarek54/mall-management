<?php

use App\Notifications\TenantResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('sends a reset link for a known email', function () {
    Notification::fake();
    $tenant = makeTenant(['email' => 'known@t.test', 'password' => 'secret-pw']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'known@t.test'])
        ->assertOk()
        ->assertJsonStructure(['message']);

    Notification::assertSentTo($tenant, TenantResetPasswordNotification::class);
});

it('does not reveal whether an email is unregistered', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@t.test'])
        ->assertOk();

    Notification::assertNothingSent();
});

it('resets the password with a valid token and revokes tokens', function () {
    $tenant = makeTenant(['email' => 'reset@t.test', 'password' => 'old-password']);
    $tenant->createToken('old-device', ['tenant:*']); // should be revoked
    $token = Password::broker('tenants')->createToken($tenant);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'reset@t.test',
        'password' => 'BrandNew-Pw1',
        'password_confirmation' => 'BrandNew-Pw1',
    ])->assertOk();

    expect(Hash::check('BrandNew-Pw1', $tenant->fresh()->password))->toBeTrue();
    expect($tenant->fresh()->tokens()->count())->toBe(0);
});

it('rejects an invalid reset token', function () {
    makeTenant(['email' => 'reset2@t.test', 'password' => 'old-password']);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'totally-wrong-token',
        'email' => 'reset2@t.test',
        'password' => 'BrandNew-Pw1',
        'password_confirmation' => 'BrandNew-Pw1',
    ])->assertStatus(422);
});

it('changes the password when the current one is correct', function () {
    $tenant = makeTenant(['password' => 'current-pw']);
    $headers = apiHeaders($tenant, 'current-device');
    $tenant->createToken('other-device', ['tenant:*']); // should be revoked

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'current-pw',
        'password' => 'Replacement-1',
        'password_confirmation' => 'Replacement-1',
    ], $headers)->assertOk();

    expect(Hash::check('Replacement-1', $tenant->fresh()->password))->toBeTrue();
    // current device kept, other revoked
    expect($tenant->fresh()->tokens()->count())->toBe(1);
});

it('rejects a password change with the wrong current password', function () {
    $tenant = makeTenant(['password' => 'current-pw']);

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'WRONG',
        'password' => 'Replacement-1',
        'password_confirmation' => 'Replacement-1',
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['currentPassword']);
});
