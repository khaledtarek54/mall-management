<?php

use App\Models\Asset;
use App\Models\Invoice;
use App\Models\MaintenanceRequest;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

/**
 * Guards the demo seed data: it must run end-to-end without error, carry the
 * Egyptian tenant roster, and contain zero "Jawad" references (owner is now
 * Atriom Developments).
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('seeds the full demo dataset without error', function () {
    expect(Tenant::count())->toBe(33);
    expect(Invoice::count())->toBeGreaterThan(0);
    expect(Asset::where('code', 'AW')->exists())->toBeTrue();
    expect(Asset::where('code', 'AW')->first()->units()->where('status', 'occupied')->count())->toBe(33);
    // Maintenance seeding depends on the portal-tenant emails matching the
    // generator — guard that coordination so a domain rename can't silently
    // drop the maintenance demo data.
    expect(MaintenanceRequest::count())->toBeGreaterThan(0);
});

it('creates the owner under the atriom domain with the owner role', function () {
    $owner = User::where('email', 'owner@atriom.test')->first();

    expect($owner)->not->toBeNull();
    expect($owner->hasRole('owner'))->toBeTrue();
    expect(Asset::where('code', 'AW')->first()->metadata['owner'])->toBe('Atriom Developments');
});

it('contains no "Jawad" references anywhere in the seeded data', function () {
    expect(User::where('email', 'like', '%jawad%')->orWhere('name', 'like', '%Jawad%')->count())->toBe(0);
    expect(Tenant::where('name', 'like', '%Jawad%')->orWhere('email', 'like', '%jawad%')->count())->toBe(0);

    foreach (Asset::all() as $asset) {
        expect(json_encode($asset->metadata ?? []))->not->toContain('Jawad');
    }
});

it('gives every portal-login tenant an unpaid invoice for the Pay Now demo', function () {
    foreach (['tenant1@atriomwalk.test', 'tenant2@atriomwalk.test', 'tenant3@atriomwalk.test'] as $email) {
        $tenant = Tenant::where('email', $email)->first();
        expect($tenant)->not->toBeNull("Expected portal tenant: {$email}");
        expect(
            Invoice::where('tenant_id', $tenant->id)->where('balance', '>', 0)->exists()
        )->toBeTrue("Portal tenant {$email} needs an unpaid invoice for the demo");
    }
});

it('uses recognizable Egyptian retail brands', function () {
    foreach (['Cilantro', 'Buffalo Burger', 'Cook Door', 'Seoudi Market', 'B.TECH', 'Magrabi Optical'] as $brand) {
        expect(Tenant::where('name', $brand)->exists())->toBeTrue("Expected seeded tenant: {$brand}");
    }
});
