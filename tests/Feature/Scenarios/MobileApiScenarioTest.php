<?php

/*
|--------------------------------------------------------------------------
| Mobile API (v1) — cross-cutting scenarios
|--------------------------------------------------------------------------
| The per-endpoint contract is already covered under tests/Feature/Api/V1/*
| (login token issuance, each list/show/write, attachment rules, Paymob,
| demo-pay, password flows, devices). These scenarios add the NET-NEW,
| cross-cutting guarantees those files do not assert:
|
|   - the full token round-trip (login → use the returned accessToken to
|     reach the tenant's own data);
|   - cross-guard / cross-tenant token isolation (a Tenant-A token never
|     resolves Tenant-B data; an admin User cannot mint a tenant-api token
|     that the guard accepts);
|   - token lifecycle effects on protected endpoints (password reset and
|     change revoke the bearer end-to-end, not just the DB row);
|   - scoping leaks the existing show-tests miss (balance / statement /
|     comment endpoints never read another tenant's records);
|   - pagination clamp boundaries (per_page floor & ceiling);
|   - a token issued with a reduced ability still works (no ability gate on
|     the routes), and an empty/garbage bearer is 401.
|
| Auth uses the shared apiHeaders($tenant) helper (mints a `tenant:*` token)
| unless a scenario specifically needs the login-issued token.
*/

use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantSalesDeclaration;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

// ---------------------------------------------------------------------------
// Helpers local to this file.
// ---------------------------------------------------------------------------

/** A tenant with a known password so the login endpoint can be exercised. */
function loginableTenant(array $attrs = []): Tenant
{
    $tenant = makeTenant(array_merge([
        'password' => Hash::make('secret-pw'),
        'status' => 'active',
    ], $attrs));

    // The login endpoint authenticates a PERSON since 2026-09-05, so the company needs one — on
    // its own address, exactly as the backfill migration gives the tenants that already existed.
    tenantLogin($tenant, 'secret-pw');

    return $tenant;
}

/** Log a tenant in via the public endpoint and return the issued bearer. */
function loginAndGetToken(Tenant $tenant, string $device = 'iPhone'): string
{
    // The response envelope is camelCased on the way out (accessToken), per the
    // Flutter contract — see CamelCaseResponseKeys middleware.
    return test()->postJson('/api/v1/auth/login', [
        'email' => $tenant->email,
        'password' => 'secret-pw',
        'device_name' => $device,
    ])->assertOk()->json('accessToken');
}

// ===========================================================================
// CASE CLASS: token auth round-trip (happy path through the public door)
// ===========================================================================

it('carries a login-issued token through to the tenant\'s own profile', function () {
    // The login tests stop at token issuance; this follows the very token the
    // login handed back into a protected endpoint and lands on the right tenant.
    $tenant = loginableTenant(['name' => 'Round Trip Co']);
    $bearer = loginAndGetToken($tenant);

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$bearer}"])
        ->assertOk()
        ->assertJsonPath('data.id', $tenant->id)
        ->assertJsonPath('data.name', 'Round Trip Co');
});

it('reaches the tenant\'s own invoices with a login-issued token', function () {
    $tenant = loginableTenant();
    makeInvoice(makeLease(makeUnit(makeAsset()), $tenant));
    $bearer = loginAndGetToken($tenant);

    $this->getJson('/api/v1/me/invoices', ['Authorization' => "Bearer {$bearer}"])
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

// ===========================================================================
// CASE CLASS: negative — unauthenticated / malformed bearers
// ===========================================================================

it('rejects a protected endpoint with no Authorization header (401)', function () {
    // Covers the un-headered case across more than the one endpoint the
    // per-resource files probe.
    $this->getJson('/api/v1/me/payments')->assertUnauthorized();
    $this->getJson('/api/v1/me/requests')->assertUnauthorized();
    $this->getJson('/api/v1/me/sales-declarations')->assertUnauthorized();
    $this->getJson('/api/v1/me/balance')->assertUnauthorized();
});

it('rejects an empty bearer value (401)', function () {
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '])->assertUnauthorized();
});

it('rejects a structurally-plausible but unknown token (401)', function () {
    // id|hash shape that no DB row backs.
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer 999|'.str_repeat('a', 40)])
        ->assertUnauthorized();
});

// ===========================================================================
// CASE CLASS: cross-guard / cross-tenant token isolation
// ===========================================================================

it('resolves each tenant token to its own identity, never the other\'s', function () {
    // Two live tokens in flight at once — the guard must key on the token,
    // not on whoever was resolved last.
    $a = makeTenant(['name' => 'Tenant Alpha']);
    $b = makeTenant(['name' => 'Tenant Beta']);

    $this->getJson('/api/v1/me', apiHeaders($a))
        ->assertOk()->assertJsonPath('data.id', $a->id);

    // Sanctum caches the resolved user on the guard for the test's request
    // lifecycle; flush it so the second bearer re-resolves to Beta, not Alpha.
    Auth::forgetGuards();

    $this->getJson('/api/v1/me', apiHeaders($b))
        ->assertOk()->assertJsonPath('data.id', $b->id);
});

it('does not leak another tenant\'s invoices on the list endpoint', function () {
    // Tenant A holds a token; Tenant B has invoices. A's list is B-free.
    $a = makeTenant();
    $b = makeTenant();
    makeInvoice(makeLease(makeUnit(makeAsset()), $b));
    makeInvoice(makeLease(makeUnit(makeAsset()), $b));
    $aInvoice = makeInvoice(makeLease(makeUnit(makeAsset()), $a));

    $ids = $this->getJson('/api/v1/me/invoices', apiHeaders($a))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->json('data.*.id');

    expect($ids)->toBe([$aInvoice->id]);
});

it('rejects a Sanctum token whose tokenable is an admin User, not a Tenant', function () {
    // The tenant-api guard's provider is the Tenant model. A personal access
    // token whose tokenable is a back-office User must NOT authenticate the
    // mobile API — otherwise a staff token would hand out a tenant session.
    // (User has no HasApiTokens, so the row is crafted directly.)
    $this->seed(RolesPermissionsSeeder::class);
    $user = makeUser('super_admin');

    $plain = Str::random(40);
    // tokenable_* aren't in PersonalAccessToken::$fillable — set the morph
    // explicitly so the row truly belongs to the admin User.
    $row = new PersonalAccessToken;
    $row->forceFill([
        'tokenable_type' => $user->getMorphClass(),
        'tokenable_id' => $user->id,
        'name' => 'admin-device',
        'token' => hash('sha256', $plain),
        'abilities' => ['*'],
    ])->save();
    $bearer = $row->id.'|'.$plain;

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$bearer}"])
        ->assertUnauthorized();
});

// ===========================================================================
// CASE CLASS: scoping — the show endpoints the per-file tests don't cross-check
// ===========================================================================

it('returns 404 commenting on another tenant\'s maintenance request', function () {
    // The controller resolves via $tenant->tenantRequests()->findOrFail,
    // so a foreign id is a 404 and writes nothing.
    $tenant = makeTenant();
    $foreign = makeTenantRequest(); // owned by a fresh, unrelated tenant

    $this->postJson(
        "/api/v1/me/requests/{$foreign->id}/comments",
        ['body' => 'Trying to comment on your ticket'],
        apiHeaders($tenant),
    )->assertNotFound();

    $this->assertDatabaseMissing('tenant_request_comments', [
        'tenant_request_id' => $foreign->id,
    ]);
});

it('returns 404 cancelling another tenant\'s maintenance request', function () {
    $tenant = makeTenant();
    $foreign = makeTenantRequest(['status' => 'submitted']);

    $this->postJson(
        "/api/v1/me/requests/{$foreign->id}/cancel",
        [],
        apiHeaders($tenant),
    )->assertNotFound();

    expect($foreign->fresh()->status)->toBe('submitted');
});

it('computes the balance from the tenant\'s own invoices only', function () {
    // Balance test in ProfileTest only covers a single tenant; this proves a
    // sibling tenant's overdue invoice does not inflate the figures.
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    makeInvoice($lease, ['status' => 'issued', 'due_date' => now()->addDays(5), 'balance' => 3000, 'total' => 3000]);

    // A loud, overdue invoice owned by someone else.
    $other = makeTenant();
    makeInvoice(makeLease(makeUnit(makeAsset()), $other), [
        'status' => 'overdue', 'due_date' => now()->subDays(10), 'balance' => 99000, 'total' => 99000,
    ]);

    $this->getJson('/api/v1/me/balance', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('data.outstanding', 3000)
        ->assertJsonPath('data.overdue', 0)
        ->assertJsonPath('data.openCount', 1);
});

it('returns 404 showing a sales declaration belonging to another tenant', function () {
    // ShowSalesDeclaration scoping isn't probed in SalesDeclarationsTest.
    $tenant = makeTenant();
    $foreignLease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'has_percentage_rent' => true,
        'percentage_rent_rate' => 5,
        'percentage_rent_threshold' => 100000,
        'percentage_rent_calculation_type' => 'artificial',
    ]);
    $foreignDeclaration = TenantSalesDeclaration::create([
        'lease_id' => $foreignLease->id,
        'period_start' => '2026-04-01', 'period_end' => '2026-04-30',
        'declared_sales' => 150000, 'status' => 'submitted', 'declared_at' => now(),
    ]);

    $this->getJson("/api/v1/me/sales-declarations/{$foreignDeclaration->id}", apiHeaders($tenant))
        ->assertNotFound();
});

// ===========================================================================
// CASE CLASS: token lifecycle — revocation propagates to protected endpoints
// ===========================================================================

it('rejects the old bearer end-to-end after a password reset revokes tokens', function () {
    // PasswordTest asserts the DB rows drop to zero; this proves the bearer is
    // actually dead at the guard on the next protected call.
    $tenant = makeTenant(['email' => 'reset-e2e@t.test', 'password' => Hash::make('old-password')]);
    $bearer = tenantLogin($tenant, 'old-password')->createToken('phone', ['tenant:*'])->plainTextToken;

    // Sanity: token works first.
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$bearer}"])->assertOk();

    $resetToken = Password::broker('tenant_users')->createToken(tenantLogin($tenant));
    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $resetToken,
        'email' => 'reset-e2e@t.test',
        'password' => 'BrandNew-Pw1',
        'password_confirmation' => 'BrandNew-Pw1',
    ])->assertOk();

    // Same test request lifecycle caches the resolved guard — flush it so the
    // next call re-resolves the (now-deleted) token.
    Auth::forgetGuards();

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$bearer}"])
        ->assertUnauthorized();
});

it('keeps the calling device alive but kills siblings after change-password', function () {
    // change-password revokes other devices; the bearer that made the call
    // must remain usable on the next protected request.
    $tenant = makeTenant(['password' => Hash::make('current-pw')]);
    $caller = tenantLogin($tenant, 'current-pw')->createToken('this-device', ['tenant:*'])->plainTextToken;
    $sibling = tenantLogin($tenant)->createToken('other-device', ['tenant:*'])->plainTextToken;

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'current-pw',
        'password' => 'Replacement-1',
        'password_confirmation' => 'Replacement-1',
    ], ['Authorization' => "Bearer {$caller}"])->assertOk();

    Auth::forgetGuards();

    // Caller still works.
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$caller}"])->assertOk();

    Auth::forgetGuards();

    // Sibling is dead.
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$sibling}"])->assertUnauthorized();
});

// ===========================================================================
// CASE CLASS: ability gate — routes don't require a specific token ability
// ===========================================================================

it('accepts a token minted without the tenant:* ability (routes have no ability gate)', function () {
    // apiHeaders mints `tenant:*`; the routes apply only auth:tenant-api, no
    // ->can(...) middleware. A narrower/empty-ability token must still pass —
    // this pins that contract so a future ability gate is a conscious change.
    $tenant = makeTenant();
    $bearer = tenantLogin($tenant)->createToken('locked-down', [])->plainTextToken;

    // `data.id` stays the COMPANY's: the token belongs to a person, and /me answers for the
    // business they act for — which is what every downstream screen in the app is keyed on.
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$bearer}"])
        ->assertOk()
        ->assertJsonPath('data.id', $tenant->id);
});

// ===========================================================================
// CASE CLASS: pagination clamp boundaries
// ===========================================================================

it('clamps per_page to the 100 ceiling', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant);
    foreach (range(1, 3) as $ignored) {
        makeInvoice($lease);
    }

    // Ask for an absurd page size; the meta must report the 100 cap.
    $this->getJson('/api/v1/me/invoices?per_page=9999', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('meta.perPage', 100);
});

it('floors a non-positive per_page back to the default of 25', function () {
    $tenant = makeTenant();
    makeInvoice(makeLease(makeUnit(makeAsset()), $tenant));

    // per_page=0 → falsey → default 25 (per ApiController::perPage).
    $this->getJson('/api/v1/me/invoices?per_page=0', apiHeaders($tenant))
        ->assertOk()
        ->assertJsonPath('meta.perPage', 25);
});

// ===========================================================================
// CASE CLASS: write/action endpoint behaves over the token path
// ===========================================================================

it('submits a maintenance request over the token and persists it to the tenant', function () {
    // A key write endpoint exercised purely through the bearer path: an active
    // lease resolves the unit, the row lands on the calling tenant, channel=portal.
    config(['integrations.paymob.enabled' => false]);
    $tenant = loginableTenant();
    makeLease(makeUnit(makeAsset()), $tenant); // active lease → unit resolution
    $bearer = loginAndGetToken($tenant);

    $this->postJson('/api/v1/me/requests', [
        'title' => 'Door handle loose',
        'description' => 'The main door handle is about to fall off.',
        'category' => 'other',
        'priority' => 'medium',
    ], ['Authorization' => "Bearer {$bearer}"])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Door handle loose')
        ->assertJsonPath('data.status', 'submitted');

    $this->assertDatabaseHas('tenant_requests', [
        'tenant_id' => $tenant->id,
        'title' => 'Door handle loose',
        'channel' => 'portal',
    ]);
});

it('demo-pays the tenant\'s own invoice through the bearer and clears the balance', function () {
    // Demo pay is the headline tenant action while Paymob is off — drive it via
    // the token and assert the real capture path zeroes the invoice.
    config(['integrations.paymob.enabled' => false]);
    $tenant = makeTenant();
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset()), $tenant), [
        'status' => 'issued', 'paid_amount' => 0, 'balance' => 11400,
    ]);

    $this->postJson("/api/v1/me/invoices/{$invoice->id}/pay-demo", [], apiHeaders($tenant))
        ->assertCreated()
        ->assertJsonPath('data.status', 'captured')
        ->assertJsonPath('data.amount', 11400);

    $invoice->refresh();
    expect((float) $invoice->balance)->toBe(0.0);
    expect($invoice->status)->toBe('paid');
    expect(Payment::where('tenant_id', $tenant->id)->where('gateway', 'demo')->count())->toBe(1);
});
