<?php

use App\Filament\Imports\TenantImporter;
use Filament\Facades\Filament;

/**
 * Module-02 close-out — tenant access control, cross-property AR isolation, and ETA identity.
 */

// --- HIGH: a blacklisted/inactive company must lose portal + API access ---

it('blocks a blacklisted company\'s portal user from the portal', function () {
    $portal = Filament::getPanel('portal');

    $blocked = makeTenantUser(makeTenant(['status' => 'blacklisted']));
    $inactive = makeTenantUser(makeTenant(['status' => 'inactive']));
    $active = makeTenantUser(makeTenant(['status' => 'active']));

    expect($blocked->canAccessPanel($portal))->toBeFalse()
        ->and($inactive->canAccessPanel($portal))->toBeFalse()
        ->and($active->canAccessPanel($portal))->toBeTrue();
});

it('cuts off a tenant blacklisted mid-session on its next authenticated API request', function () {
    $tenant = makeTenant(['status' => 'active']);
    makeLease(makeUnit(makeAsset()), $tenant);
    $token = $tenant->createToken('test-device', ['tenant:*'])->plainTextToken;

    // Active → authenticated request works.
    $this->withToken($token)->getJson('/api/v1/me/balance')->assertOk();

    // Blacklisted mid-session → the very next request is refused (and the token is revoked).
    $tenant->update(['status' => 'blacklisted']);
    // Each real request is a fresh process that re-resolves the token; forget the cached guard user
    // so this in-process second request re-resolves too (otherwise the test's shared container
    // returns the first request's cached active tenant — an artifact, not production behaviour).
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me/balance')->assertStatus(403);
});

// --- HIGH: cross-property AR isolation on the admin surfaces (shared tenant) ---

it('scopes delinquency + outstanding balance to the given properties', function () {
    $tenant = makeTenant(['status' => 'active']);
    $mallA = makeAsset(['code' => 'T2-A']);
    $mallB = makeAsset(['code' => 'T2-B']);
    makeLease(makeUnit($mallA), $tenant);
    $leaseB = makeLease(makeUnit($mallB), $tenant);

    // An overdue invoice in mall B only.
    makeInvoice($leaseB, [
        'subtotal' => 500, 'vat_amount' => 0, 'total' => 500, 'paid_amount' => 0, 'balance' => 500,
        'due_date' => now()->subDays(10), 'status' => 'overdue',
    ]);

    expect($tenant->isDelinquent())->toBeTrue()                    // whole company: delinquent
        ->and($tenant->isDelinquent([$mallA->id]))->toBeFalse()    // scoped to mall A: not
        ->and($tenant->isDelinquent([$mallB->id]))->toBeTrue()
        ->and($tenant->outstandingBalance([$mallA->id]))->toBe(0.0)
        ->and($tenant->outstandingBalance([$mallB->id]))->toBe(500.0)
        ->and($tenant->outstandingBalance())->toBe(500.0);          // unscoped = whole company
});

// --- ETA identity: tax_id stored as bare digits ---

it('normalises tax_id to bare digits on save', function () {
    $t = makeTenant(['tax_id' => '123-456-789']);
    expect($t->fresh()->tax_id)->toBe('123456789');

    $t->update(['tax_id' => '987654321']);
    expect($t->fresh()->tax_id)->toBe('987654321');

    $t->update(['tax_id' => '']);
    expect($t->fresh()->tax_id)->toBeNull();
});

// --- Importer rules align with the schema + the ETA format ---

it('importer rejects an unstorable type and a malformed tax_id', function () {
    $rules = collect(TenantImporter::getColumns())
        ->keyBy(fn ($c) => $c->getName())
        ->map(fn ($c) => $c->getDataValidationRules());

    expect($rules['type'])->not->toContain('in:individual,company,foreign')
        ->and($rules['type'])->toContain('in:individual,company')
        ->and(collect($rules['tax_id'])->contains(fn ($r) => is_string($r) && str_contains($r, 'regex')))->toBeTrue();
});
