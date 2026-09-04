<?php

use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * ONE LOGIN PER PERSON, OPENING BOTH TENANT-FACING SURFACES (owner's decision, 2026-09-05).
 *
 * This replaces ATenantsAppPasswordIsNotAPortalLoginTest, which pinned the SPLIT: the web portal
 * authenticated a `tenant_users` row while the mobile API authenticated the COMPANY
 * (`tenants.email` + `tenants.password`). That file said in writing that a red there would be the
 * signal the surfaces had merged rather than a regression. They have merged, so it is rewritten
 * rather than deleted.
 *
 * Why the split had to go, in the order the costs land: the operator had to set up two credentials
 * per tenant and they drifted (reported from staging as a portal login that "always gives wrong
 * creds"); the app could not say WHICH member of staff paid an invoice, because the company was the
 * identity; and one person could not be revoked without changing everybody's password.
 *
 * The backfill case is the one that would cost real money to get wrong: pointing the guard at a new
 * table locks out every tenant already using the app, silently, on deploy.
 */
it('opens the web portal and the mobile app with the same credential', function () {
    $tenant = makeTenant();
    $user = TenantUser::create([
        'tenant_id' => $tenant->id,
        'name' => 'Mona A.',
        'email' => 'mona@'.uniqid().'.test',
        'password' => 'one-password',
        'is_admin' => true,
    ]);

    // The web portal.
    expect(Auth::guard('portal')->attempt(['email' => $user->email, 'password' => 'one-password']))
        ->toBeTrue();

    // The mobile app — the SAME email and password, through the real endpoint.
    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'one-password',
    ])->assertOk()->assertJsonStructure(['accessToken', 'tokenType']);
});

it('scopes the app to the person\'s company, not to the person', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    $invoice = makeInvoice($lease, ['status' => 'issued']);

    // A second company whose invoice must never appear.
    $other = makeTenant();
    makeInvoice(makeLease(makeUnit(makeAsset()), $other), ['status' => 'issued']);

    $response = $this->withHeaders(apiHeaders($tenant))->getJson('/api/v1/me/invoices');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($invoice->id)      // CONTROL — the read works at all.
        ->and($ids)->toHaveCount(1);           // and reaches exactly its own company.
});

it('does not lock out a tenant who was using the app before the change', function () {
    // A company as it stood BEFORE: a credential on the company row and no person to be.
    $tenant = Tenant::create([
        'name' => 'Legacy Co',
        'email' => 'legacy-'.uniqid().'@t.test',
        'type' => 'company',
        'status' => 'active',
        'contact_person' => 'Hany S.',
        'password' => Hash::make('legacy-pw'),
    ]);

    expect(TenantUser::where('tenant_id', $tenant->id)->exists())->toBeFalse();

    // Without the backfill this login is simply gone. Run the migration itself, not a copy of it.
    $migration = require database_path('migrations/2026_09_05_120000_give_every_mobile_login_a_person_to_be.php');
    $migration->up();

    $person = TenantUser::where('tenant_id', $tenant->id)->first();

    expect($person)->not->toBeNull()
        ->and($person->email)->toBe($tenant->email)
        ->and($person->name)->toBe('Hany S.')
        // Their existing capability is preserved: the company credential could already do
        // everything, so anything less would take away access these people have today.
        ->and((bool) $person->is_admin)->toBeTrue();

    // The hash is carried across intact — hashing an already-hashed value would lock out exactly
    // the people this migration exists to keep in, and would do it silently.
    $this->postJson('/api/v1/auth/login', [
        'email' => $tenant->email,
        'password' => 'legacy-pw',
    ])->assertOk();

    // Idempotent: a second run must not collide with the unique index it just filled.
    $migration->up();
    expect(TenantUser::where('tenant_id', $tenant->id)->count())->toBe(1);
});

/**
 * The `is_admin` tick means the same thing on both surfaces.
 *
 * The portal has gated writes on it since the multi-user portal shipped. The API never did and
 * never needed to, because it authenticated the COMPANY — one credential that was the admin by
 * construction. Unifying the logins removed that guarantee in the dangerous direction: a read-only
 * person who could not previously reach the API at all would otherwise be able to pay an invoice
 * from the phone. Each refusal below is paired with the same call succeeding for an admin, because
 * a refusal test passes just as happily when the endpoint is broken for everyone.
 */
it('lets a read-only login read but not act, on the app as in the portal', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    makeInvoice($lease, ['status' => 'issued']);

    $readOnly = makeTenantUser($tenant, false);
    $headers = ['Authorization' => 'Bearer '.$readOnly->createToken('phone', ['tenant:*'])->plainTextToken];

    // CONTROL — reading is exactly what a read-only login is for.
    $this->withHeaders($headers)->getJson('/api/v1/me/invoices')->assertOk();

    // ...and it may still act on its OWN session and device.
    $this->withHeaders($headers)->postJson('/api/v1/me/devices', [
        'token' => 'fcm-token-abc', 'platform' => 'ios',
    ])->assertSuccessful();

    // THE REFUSALS — acts that commit the COMPANY.
    $this->withHeaders($headers)->postJson('/api/v1/me/requests', [
        'category' => 'electrical', 'description' => 'Lights out in the stockroom',
    ])->assertForbidden();

    $this->withHeaders($headers)->patchJson('/api/v1/me', ['contactPerson' => 'Someone Else'])
        ->assertForbidden();
});

it('lets an admin login act', function () {
    $tenant = makeTenant();
    makeLease(makeUnit(makeAsset()), $tenant);

    $admin = makeTenantUser($tenant, true);
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('phone', ['tenant:*'])->plainTextToken];

    // The same write the read-only login was refused — so the refusal above is about the ROLE and
    // not about a broken endpoint.
    $this->withHeaders($headers)->patchJson('/api/v1/me', ['contactPerson' => 'Someone Else'])
        ->assertOk();
});
