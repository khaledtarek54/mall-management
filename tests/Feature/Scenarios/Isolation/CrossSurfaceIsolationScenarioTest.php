<?php

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Asset;
use App\Support\Portal;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Cross-surface property-isolation scenarios — proves the three tenant-facing
 * surfaces (mobile API, portal, admin All-Properties mode) each honour the
 * isolation boundary that matters for THAT surface:
 *
 *   - Mobile API (Sanctum 'tenant-api' → Tenant): a token reads its OWN data;
 *     a cross-tenant id resolves to 404 (never 403 — no existence enumeration).
 *   - Portal (TenantUser, guard 'portal'): scoped to the login's tenant_id
 *     across EVERY property the tenant leases in — tenant-scoped, NOT
 *     asset-scoped. Another tenant's rows are invisible.
 *   - Admin All-Properties: a super_admin in ALL mode sees BOTH properties;
 *     a manager restricted to A in ALL mode still sees ONLY A's records — the
 *     crucial "restricted user stays pinned in All-mode" guarantee, proven for
 *     both scoping paths (direct-FK Units, chain-scoped Invoices).
 *
 * Sibling files (ScopingScenarioTest, ResourceScopingTest, …) cover per-module
 * read scope; this file only adds the cross-surface + All-Properties cases.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

// ---------------------------------------------------------------------------
// (a) READ-SCOPE — mobile API: a token reads its OWN tenant's data.
// ---------------------------------------------------------------------------

it('mobile API: a tenant token reads only its own invoices, never another tenant\'s', function () {
    $mine = makeTenant();
    $myLease = makeLease(makeUnit(makeAsset(['code' => 'API-A'])), $mine);
    makeInvoice($myLease);
    makeInvoice($myLease);

    // Another tenant in a different property — must not leak into the list.
    $other = makeTenant();
    makeInvoice(makeLease(makeUnit(makeAsset(['code' => 'API-B'])), $other));

    $response = $this->getJson('/api/v1/me/invoices', apiHeaders($mine))
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['total']]);

    expect($response->json('meta.total'))->toBe(2);
});

it('mobile API: GET /me profile returns the authenticated tenant only', function () {
    $mine = makeTenant(['name' => 'Mine Co']);
    makeTenant(['name' => 'Other Co']);

    $this->getJson('/api/v1/me', apiHeaders($mine))
        ->assertOk()
        ->assertJsonPath('data.id', $mine->id)
        ->assertJsonPath('data.name', 'Mine Co');
});

// ---------------------------------------------------------------------------
// (a) CROSS-TENANT — mobile API returns 404, NOT 403 (no enumeration).
// ---------------------------------------------------------------------------

it('mobile API: another tenant\'s invoice id resolves to 404, not 403', function () {
    $mine = makeTenant();
    $other = makeTenant();
    $othersInvoice = makeInvoice(makeLease(makeUnit(makeAsset(['code' => 'X404'])), $other));

    // 404 (not 403) so we never confirm the row exists to a stranger.
    $this->getJson("/api/v1/me/invoices/{$othersInvoice->id}", apiHeaders($mine))
        ->assertNotFound();
});

it('mobile API: an unauthenticated request is 401', function () {
    $this->getJson('/api/v1/me/invoices')->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// (d) PORTAL — tenant-scoped (NOT asset-scoped): a portal user sees only
//     their own tenant's data across every property the tenant leases in.
// ---------------------------------------------------------------------------

it('portal: a portal login is scoped to its own tenant_id, invisible to another tenant\'s data', function () {
    $tenantA = makeTenant();
    $tenantB = makeTenant();

    // tenantA leases in TWO different properties — portal scope must span both.
    $leaseA1 = makeLease(makeUnit(makeAsset(['code' => 'POR-1'])), $tenantA);
    $leaseA2 = makeLease(makeUnit(makeAsset(['code' => 'POR-2'])), $tenantA);
    makeInvoice($leaseA1);
    makeInvoice($leaseA2);

    // tenantB has its own invoice — must never appear for tenantA's login.
    makeInvoice(makeLease(makeUnit(makeAsset(['code' => 'POR-3'])), $tenantB));

    $portalUser = makeTenantUser($tenantA);
    $this->actingAs($portalUser, 'portal');

    // The portal context resolves the login's tenant_id — the value every
    // portal query scopes to (app/Support/Portal.php).
    expect(Portal::tenantId())->toBe($tenantA->id);

    // Drive the portal InvoiceResource query exactly as its ListInvoices page
    // builds it: getEloquentQuery() applies `where('tenant_id', Portal::tenantId())`.
    $ids = App\Filament\Portal\Resources\Invoices\InvoiceResource::getEloquentQuery()
        ->pluck('tenant_id')->unique()->all();

    // Tenant-scoped, NOT asset-scoped: BOTH of tenantA's invoices (across two
    // properties) are visible, and nothing belongs to tenantB.
    expect(App\Filament\Portal\Resources\Invoices\InvoiceResource::getEloquentQuery()->count())->toBe(2);
    expect($ids)->toBe([$tenantA->id]);
});

// ---------------------------------------------------------------------------
// (b) ALL-PROPERTIES — super_admin sees BOTH; a restricted user stays pinned.
//     Direct-FK path (Units, BypassesScopingOnAll).
// ---------------------------------------------------------------------------

it('admin All-mode (Units): super_admin sees BOTH properties\' units', function () {
    $a = makeAsset(['code' => 'ALLU-A']);
    $b = makeAsset(['code' => 'ALLU-B']);
    $ua = makeUnit($a, ['code' => 'UA-ALL']);
    $ub = makeUnit($b, ['code' => 'UB-ALL']);

    $this->actingAs(makeUser('super_admin'));

    $codes = asTenant(ensureAllPropertiesAsset(), fn () => scopedResourceQuery(UnitResource::class)
        ->pluck('code')->all());

    expect($codes)->toContain('UA-ALL')->toContain('UB-ALL');
});

it('admin All-mode (Units): a manager restricted to A sees ONLY A\'s units, never B\'s', function () {
    $a = makeAsset(['code' => 'RESU-A']);
    $b = makeAsset(['code' => 'RESU-B']);
    makeUnit($a, ['code' => 'UA-RES']);
    makeUnit($b, ['code' => 'UB-RES']);

    // Restricted user assigned to A only — pin must survive All-Properties mode.
    $this->actingAs(makeUser('manager', [$a->id]));

    $codes = asTenant(ensureAllPropertiesAsset(), fn () => scopedResourceQuery(UnitResource::class)
        ->pluck('code')->all());

    expect($codes)->toContain('UA-RES')->not->toContain('UB-RES');
});

// ---------------------------------------------------------------------------
// (b) ALL-PROPERTIES — chain-scoped path (Invoices, ScopesViaProperty).
// ---------------------------------------------------------------------------

it('admin All-mode (Invoices): super_admin sees BOTH properties\' invoices', function () {
    $a = makeAsset(['code' => 'ALLI-A']);
    $b = makeAsset(['code' => 'ALLI-B']);
    $ia = makeInvoice(makeLease(makeUnit($a)));
    $ib = makeInvoice(makeLease(makeUnit($b)));

    $this->actingAs(makeUser('super_admin'));

    $ids = asTenant(ensureAllPropertiesAsset(), fn () => scopedResourceQuery(InvoiceResource::class)
        ->pluck('id')->all());

    expect($ids)->toContain($ia->id)->toContain($ib->id);
});

it('admin All-mode (Invoices): a manager restricted to A sees ONLY A\'s invoices, never B\'s', function () {
    $a = makeAsset(['code' => 'RESI-A']);
    $b = makeAsset(['code' => 'RESI-B']);
    $ia = makeInvoice(makeLease(makeUnit($a)));
    $ib = makeInvoice(makeLease(makeUnit($b)));

    $this->actingAs(makeUser('manager', [$a->id]));

    $ids = asTenant(ensureAllPropertiesAsset(), fn () => scopedResourceQuery(InvoiceResource::class)
        ->pluck('id')->all());

    expect($ids)->toContain($ia->id)->not->toContain($ib->id);
});

// ---------------------------------------------------------------------------
// (c) WRITE-GUARD — the resource's assert*AssetInScope(...) rejects an
//     out-of-scope FK for a restricted user and allows an in-scope one.
// ---------------------------------------------------------------------------

it('write-guard (Units, direct asset_id): restricted user is rejected out-of-scope, allowed in-scope', function () {
    $a = makeAsset(['code' => 'WGU-A']);
    $b = makeAsset(['code' => 'WGU-B']);

    $this->actingAs(makeUser('manager', [$a->id]));

    // Out-of-scope property is rejected; the synthetic ALL id + null are too.
    expect(fn () => UnitResource::assertAssetInScope($b->id))->toThrow(HttpException::class);
    expect(fn () => UnitResource::assertAssetInScope(null))->toThrow(HttpException::class);

    // In-scope property passes (no throw).
    UnitResource::assertAssetInScope($a->id);
    expect(true)->toBeTrue();
});

it('write-guard (Invoices, chain via lease→unit): restricted user is rejected out-of-scope, allowed in-scope', function () {
    $a = makeAsset(['code' => 'WGI-A']);
    $b = makeAsset(['code' => 'WGI-B']);
    $leaseA = makeLease(makeUnit($a));
    $leaseB = makeLease(makeUnit($b));

    $this->actingAs(makeUser('manager', [$a->id]));

    // A lease in property B (resolved to B's asset_id) is out of scope → 403.
    expect(fn () => InvoiceResource::assertLeaseAssetInScope($leaseB->id))->toThrow(HttpException::class);
    // A null / unknown lease resolves to null asset → also rejected.
    expect(fn () => InvoiceResource::assertLeaseAssetInScope(null))->toThrow(HttpException::class);

    // The in-scope lease in property A passes.
    InvoiceResource::assertLeaseAssetInScope($leaseA->id);
    expect(true)->toBeTrue();
});

it('write-guard (Invoices, chain via invoice_id): payment-allocation guard rejects out-of-scope invoice', function () {
    $a = makeAsset(['code' => 'WGP-A']);
    $b = makeAsset(['code' => 'WGP-B']);
    $invA = makeInvoice(makeLease(makeUnit($a)));
    $invB = makeInvoice(makeLease(makeUnit($b)));

    $this->actingAs(makeUser('manager', [$a->id]));

    expect(fn () => InvoiceResource::assertInvoiceAssetInScope($invB->id))->toThrow(HttpException::class);

    InvoiceResource::assertInvoiceAssetInScope($invA->id);
    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// (b)+(c) portfolio no-op — the guard must NOT bite a super_admin, so ALL-mode
//     writes into any property remain possible for portfolio users.
// ---------------------------------------------------------------------------

it('write-guard is a no-op for a portfolio user (super_admin) — any property is in scope', function () {
    $a = makeAsset(['code' => 'PF-A']);
    $b = makeAsset(['code' => 'PF-B']);

    $this->actingAs(makeUser('super_admin'));

    // visibleAssetIds() is null for super_admin → guard never aborts.
    UnitResource::assertAssetInScope($a->id);
    UnitResource::assertAssetInScope($b->id);
    UnitResource::assertAssetInScope(null);
    expect(true)->toBeTrue();
});
