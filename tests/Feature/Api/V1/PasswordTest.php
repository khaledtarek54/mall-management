<?php

use App\Notifications\TenantResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('sends a reset link for a known email', function () {
    Notification::fake();
    $tenant = makeTenant(['email' => 'known@t.test']);
    $login = tenantLogin($tenant);
    $login->update(['email' => 'known@t.test', 'password' => 'secret-pw']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'known@t.test'])
        ->assertOk()
        ->assertJsonStructure(['message']);

    // The link goes to the PERSON now, and it must still be the MOBILE notification: whoever is
    // locked out of the app has to be sent back into the app, not into the web portal.
    Notification::assertSentTo($login, TenantResetPasswordNotification::class);
});

it('does not reveal whether an email is unregistered', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@t.test'])
        ->assertOk();

    Notification::assertNothingSent();
});

it('resets the password with a valid token and revokes tokens', function () {
    $tenant = makeTenant(['email' => 'reset@t.test']);
    $login = tenantLogin($tenant);
    $login->update(['email' => 'reset@t.test', 'password' => 'old-password']);
    $login->createToken('old-device', ['tenant:*']); // should be revoked
    // The reset token is issued by the broker that will consume it — `tenant_users` since the two
    // tenant-facing logins merged. Minting it on the old broker leaves the token in the same table
    // under a different provider and the reset silently fails to find its subject.
    $token = Password::broker('tenant_users')->createToken($login);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'reset@t.test',
        'password' => 'BrandNew-Pw1',
        'password_confirmation' => 'BrandNew-Pw1',
    ])->assertOk();

    expect(Hash::check('BrandNew-Pw1', $login->fresh()->password))->toBeTrue();
    expect($login->fresh()->tokens()->count())->toBe(0);
});

it('rejects an invalid reset token', function () {
    tenantLogin(makeTenant(['email' => 'reset2@t.test']))->update(['email' => 'reset2@t.test', 'password' => 'old-password']);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'totally-wrong-token',
        'email' => 'reset2@t.test',
        'password' => 'BrandNew-Pw1',
        'password_confirmation' => 'BrandNew-Pw1',
    ])->assertStatus(422);
});

it('changes the password when the current one is correct', function () {
    $tenant = makeTenant();
    tenantLogin($tenant, 'current-pw');
    $headers = apiHeaders($tenant, 'current-device');
    tenantLogin($tenant)->createToken('other-device', ['tenant:*']); // should be revoked

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'current-pw',
        'password' => 'Replacement-1',
        'password_confirmation' => 'Replacement-1',
    ], $headers)->assertOk();

    expect(Hash::check('Replacement-1', tenantLogin($tenant)->fresh()->password))->toBeTrue();
    // current device kept, other revoked
    expect(tenantLogin($tenant)->fresh()->tokens()->count())->toBe(1);
});

it('rejects a password change with the wrong current password', function () {
    $tenant = makeTenant();
    tenantLogin($tenant, 'current-pw');

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'WRONG',
        'password' => 'Replacement-1',
        'password_confirmation' => 'Replacement-1',
    ], apiHeaders($tenant))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['currentPassword']);
});
