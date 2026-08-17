<?php

use App\Enums\PartyType;
use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

/**
 * Guards the demo seed data: it must run end-to-end without error, carry the
 * Egyptian tenant roster, and contain zero "Jawad" references (owner is now
 * Atriom Developments).
 *
 * Deliberately ONE test over one seed run. Every assertion below is a property of
 * the SAME seeded database, and `DatabaseSeeder` is the single most expensive fixture
 * in the suite — split across six `it()` blocks it re-seeded the whole demo six times
 * to read six different columns off it. Nothing is asserted here that wasn't before;
 * the failure messages carry which property broke.
 */
it('seeds a complete, correctly-branded demo dataset', function () {
    $this->seed(DatabaseSeeder::class);

    // --- runs end to end and produces the expected shape -------------------------
    // Counted BY PARTY TYPE, not as one literal — for exactly the reason stated three assertions
    // down about the unit count. `toBe(33)` broke the moment module 37 seeded sold units, because a
    // unit OWNER is a `tenants` row distinguished by `party_type`: the total went 33 -> 39 with
    // nothing wrong. Bumping the literal would only defer the same breakage to the next expansion.
    //
    // This is also STRICTER than the total it replaces. A single count of 39 is satisfied by 39
    // retailers and zero owners — it could not notice the owner demo data disappearing, which is
    // the half most likely to regress because it is the newest.
    expect(Tenant::where('party_type', PartyType::Retailer->value)->count())->toBeGreaterThan(20);
    expect(Tenant::where('party_type', PartyType::UnitOwner->value)->count())->toBeGreaterThan(0);
    expect(Invoice::count())->toBeGreaterThan(0);
    expect(Asset::where('code', 'AW')->exists())->toBeTrue();
    // Every unit held by a live lease is occupied, and nothing else is. Asserted as the INVARIANT
    // rather than as a count: the literal was 34 and broke the moment the seeder grew a demo
    // expansion, which is a true statement about the demo and says nothing about correctness.
    $aw = Asset::where('code', 'AW')->first();
    $heldUnitIds = Lease::query()
        ->whereIn('status', ['active', 'pending_approval'])
        ->with('units')
        ->get()
        ->flatMap(fn (Lease $l) => $l->units->pluck('id'))
        ->unique();

    expect($aw->units()->where('status', 'occupied')->pluck('id')->sort()->values()->all())
        ->toBe($heldUnitIds->sort()->values()->all());
    expect(Lease::has('units', '>', 1)->exists())->toBeTrue();   // demo multi-unit lease
    // Maintenance seeding depends on the portal-tenant emails matching the
    // generator — guard that coordination so a domain rename can't silently
    // drop the maintenance demo data.
    expect(TenantRequest::count())->toBeGreaterThan(0);

    // --- the owner is Atriom, under the atriom domain, with the owner role -------
    $owner = User::where('email', 'owner@atriom.test')->first();

    expect($owner)->not->toBeNull();
    expect($owner->hasRole('owner'))->toBeTrue();
    expect(Asset::where('code', 'AW')->first()->metadata['owner'])->toBe('Atriom Developments');

    // --- zero "Jawad" references anywhere ----------------------------------------
    expect(User::where('email', 'like', '%jawad%')->orWhere('name', 'like', '%Jawad%')->count())->toBe(0);
    expect(Tenant::where('name', 'like', '%Jawad%')->orWhere('email', 'like', '%jawad%')->count())->toBe(0);

    foreach (Asset::all() as $asset) {
        expect(json_encode($asset->metadata ?? []))->not->toContain('Jawad');
    }

    // --- every portal-login tenant has an unpaid invoice for the Pay Now demo ----
    foreach (['tenant1@atriomwalk.test', 'tenant2@atriomwalk.test', 'tenant3@atriomwalk.test'] as $email) {
        $tenant = Tenant::where('email', $email)->first();
        expect($tenant)->not->toBeNull("Expected portal tenant: {$email}");
        expect(
            Invoice::where('tenant_id', $tenant->id)->where('balance', '>', 0)->exists()
        )->toBeTrue("Portal tenant {$email} needs an unpaid invoice for the demo");
    }

    // --- no orphan-header invoice that renders blank -----------------------------
    // Regression: seedArAgingSpread created invoice headers (subtotal/VAT/total) with NO
    // invoice_items, so those invoices rendered blank in the admin edit form and posted an
    // incomplete GL revenue split. Every invoice must carry at least one line item.
    $orphans = Invoice::doesntHave('items')->pluck('number')->all();

    expect($orphans)->toBe([], 'Invoices with a total but no line items: '.implode(', ', $orphans));

    // --- recognizable Egyptian retail brands -------------------------------------
    foreach (['Cilantro', 'Buffalo Burger', 'Cook Door', 'Seoudi Market', 'B.TECH', 'Magrabi Optical'] as $brand) {
        expect(Tenant::where('name', $brand)->exists())->toBeTrue("Expected seeded tenant: {$brand}");
    }
});
