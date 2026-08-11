<?php

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Health;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Password;

/**
 * The tenant portal's password reset must resolve against `tenant_users`, not the admin table.
 *
 * `PortalPanelProvider` called `->passwordReset()` without `->authPasswordBroker(...)`, so Filament
 * resolved `Password::broker(null)` — which `config/auth.php` defaults to `users`, i.e.
 * `App\Models\User`. Two consequences, both live:
 *
 *  - **A TenantUser could never reset.** Their email is not in `users`, so the lookup returned
 *    INVALID_USER and the page said "we can't find a user with that email address". The feature
 *    that exists to remove the operator round-trip *was* the operator round-trip.
 *  - **An admin's reset could be driven from the public tenant portal.** `User::canAccessPanel()`
 *    fell through to `true` for any panel that wasn't `admin`, so Filament's own guard never
 *    fired and the portal form would mail an operator a genuine reset link built for that panel.
 *
 * The purpose-built `tenant_users` broker existed in `config/auth.php` the whole time and nothing
 * used it.
 */
it('resolves the portal panel password reset through the tenant_users broker', function () {
    $panel = Filament::getPanel('portal');

    expect($panel->getAuthPasswordBroker())->toBe('tenant_users');
});

/**
 * These two assert on `getUser()` rather than `sendResetLink()` deliberately.
 *
 * Which TABLE the broker resolves against is the whole defect; the sending half is Filament's,
 * and it supplies its own notification callback to `sendResetLink()`. Calling that bare in a test
 * would exercise Laravel's default notification and its `route('password.reset')`, which this
 * application does not define — a failure about the test harness, not about the fix.
 */
it('finds a TenantUser through the portal broker — the reset that never worked', function () {
    $tenant = Tenant::factory()->create();
    $user = TenantUser::factory()->for($tenant)->create(['email' => 'shop@atriomwalk.test']);

    expect(Password::broker('tenant_users')->getUser(['email' => $user->email]))
        ->not->toBeNull();
});

it('does NOT find an operator through the portal broker', function () {
    User::factory()->create(['email' => 'operator@mall.test']);

    // The admin lives in `users`; the portal broker looks in `tenant_users`. If this ever resolves
    // a user, the portal can once again mail an operator a reset link built for the tenant panel.
    expect(Password::broker('tenant_users')->getUser(['email' => 'operator@mall.test']))
        ->toBeNull();
});

it('would have found that operator through the default broker — what the portal was using', function () {
    User::factory()->create(['email' => 'operator@mall.test']);

    // The control that makes the assertion above mean something: the admin IS resolvable through
    // the broker the portal defaulted to, which is precisely why this was a real exposure.
    expect(Password::broker()->getUser(['email' => 'operator@mall.test']))
        ->not->toBeNull();
});

it('refuses an operator access to the portal panel', function () {
    $operator = User::factory()->create();
    $panel = Filament::getPanel('portal');

    expect($operator->canAccessPanel($panel))->toBeFalse();
});

it('still admits an operator to the admin panel — the paired control', function () {
    // Without this, `canAccessPanel()` returning false for everything would look like a fix.
    $operator = User::factory()->create();
    $operator->assignRole(
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
    );

    expect($operator->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

it('fails the health check when the mobile reset link points at a route that does not exist', function () {
    app()['env'] = 'production';
    config()->set('app.url', 'https://atriom.example');
    config()->set('app.mobile_reset_url', 'https://atriom.example/reset-password');

    $check = Health::run()['checks']['mobile_reset_url'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('APP_MOBILE_RESET_URL');
});

it('passes the health check once a real mobile reset target is configured', function () {
    app()['env'] = 'production';
    config()->set('app.url', 'https://atriom.example');
    config()->set('app.mobile_reset_url', 'atriom://reset-password');

    expect(Health::run()['checks']['mobile_reset_url']['ok'])->toBeTrue();
});
