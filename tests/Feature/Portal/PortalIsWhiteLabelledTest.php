<?php

use App\Enums\UnitOwnershipStatus;
use App\Models\UnitOwnership;
use App\Support\Filament\PortalBranding;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Lang;

/**
 * **A retailer signing into their landlord's portal should see their landlord, not the software.**
 *
 * The portal shipped `->brandName('Atriom · Tenant Portal')` — an untranslated English literal — a
 * static logo, a static favicon and no colour hook, while the admin panel next door had skinned
 * itself per property since it gained tenancy. That is the one surface where white-labelling
 * actually earns its keep: the operator's own staff know whose software this is; their tenants
 * should not have to.
 *
 * ## The rule under test, and the half that is easy to get wrong
 *
 * A tenant trading in ONE mall gets that mall. A tenant with shops in three gets the platform,
 * because branding their portal as one of the three is a claim about the other two — the same rule
 * `IssuingEntity::forViewScopedTo()` already applies to the tenant-facing PDFs, so a statement and a
 * portal cannot tell one tenant two different things.
 *
 * Every refusal below is paired with a control that must go the other way. A resolver that returned
 * null for everybody would satisfy "a chain sees the platform" on its own.
 */
beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('portal')));
afterEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    PortalBranding::forget();
});

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->mall = makeAsset(['code' => 'CFM', 'name' => 'Cairo Festival Mall', 'primary_color' => '#0f766e']);
    $this->other = makeAsset(['code' => 'MOA', 'name' => 'Mall of Arabia']);
});

it('shows a single-mall retailer their mall', function () {
    $tenant = makeTenant(['name' => 'Cafe Crema']);
    makeLease(makeUnit($this->mall), $tenant, ['status' => 'active']);

    $this->actingAs(makeTenantUser($tenant), 'portal');

    expect(PortalBranding::asset()?->id)->toBe($this->mall->id)
        ->and(PortalBranding::brandName())->toBe('Cairo Festival Mall')
        ->and(PortalBranding::themeOverride())->toContain('#0f766e');
});

it('shows a chain the platform, because one of three malls would be a claim about the other two', function () {
    $tenant = makeTenant(['name' => 'Three Shops Ltd']);
    makeLease(makeUnit($this->mall), $tenant, ['status' => 'active']);
    makeLease(makeUnit($this->other), $tenant, ['status' => 'active']);

    $this->actingAs(makeTenantUser($tenant), 'portal');

    expect(PortalBranding::asset())->toBeNull()
        ->and(PortalBranding::brandName())->toBe(__('portal.brand'))
        // No colour either — half-branding is worse than none: the chrome would be one mall's green
        // over a page listing three malls' invoices.
        ->and(PortalBranding::themeOverride())->toBe('');
});

it('shows the platform on the login page, where nobody has signed in yet', function () {
    expect(PortalBranding::asset())->toBeNull()
        ->and(PortalBranding::brandName())->toBe(__('portal.brand'))
        ->and(PortalBranding::logo())->toContain('atriom-logo');
});

it('brands for a handed-over unit owner, and not before the keys change hands', function () {
    // A unit owner is a `tenants` row too (module 37) and pays their service charge through this
    // same portal. `handed_over` is the state that BILLS, and it is the state that brands: until
    // then the operator still holds the unit and the owner has no mall to be shown.
    $owner = makeTenant(['name' => 'Mr Owner', 'party_type' => 'unit_owner']);
    $unit = makeUnit($this->mall, ['code' => 'O-01']);

    $ownership = UnitOwnership::create([
        'asset_id' => $this->mall->id,
        'unit_id' => $unit->id,
        'tenant_id' => $owner->id,
        'status' => UnitOwnershipStatus::Contracted->value,
        'contract_date' => now()->subMonth()->toDateString(),
    ]);

    $this->actingAs(makeTenantUser($owner), 'portal');

    expect(PortalBranding::asset())->toBeNull();

    PortalBranding::forget();
    $ownership->update(['status' => UnitOwnershipStatus::HandedOver->value, 'handover_date' => now()->toDateString()]);

    expect(PortalBranding::asset()?->id)->toBe($this->mall->id);
});

it('emits nothing for a colour the operator typed wrong', function () {
    $tenant = makeTenant(['name' => 'Colour Test']);
    makeLease(makeUnit($this->other), $tenant, ['status' => 'active']);

    $this->actingAs(makeTenantUser($tenant), 'portal');

    // `Mall of Arabia` has no colour: a `<style>` block is emitted only when there is something to
    // put in it, and a malformed hex would take the panel's whole chrome with it.
    expect(PortalBranding::asset()?->id)->toBe($this->other->id)
        ->and(PortalBranding::themeOverride())->toBe('');

    $this->other->update(['primary_color' => 'not-a-colour']);
    PortalBranding::forget();

    expect(PortalBranding::themeOverride())->toBe('');

    // The control: a good colour on the same asset DOES emit, so the assertions above are about the
    // validation and not about the resolver having stopped working.
    $this->other->update(['primary_color' => '#b91c1c']);
    PortalBranding::forget();

    expect(PortalBranding::themeOverride())->toContain('--primary-500: #b91c1c');
});

it('reaches the rendered page, not just the resolver', function () {
    $tenant = makeTenant(['name' => 'Rendered Retail']);
    makeLease(makeUnit($this->mall), $tenant, ['status' => 'active']);

    $this->actingAs(makeTenantUser($tenant), 'portal');

    // A resolver nothing calls is the failure this project keeps finding. Fetch the real page.
    $html = $this->get('/portal')->assertOk()->getContent();

    expect($html)->toContain('Cairo Festival Mall')
        ->and($html)->toContain('--primary-500: #0f766e')
        ->and($html)->not->toContain('Atriom · Tenant Portal');
});

it('says the platform name in Arabic too', function () {
    // The literal it replaced was English on an Arabic page. Checked with `fallback: false`, because
    // `Lang::has($key, 'ar')` falls back to English and would pass for a key that exists only in EN.
    expect(Lang::has('portal.brand', 'ar', false))->toBeTrue()
        ->and(__('portal.brand', [], 'ar'))->not->toBe(__('portal.brand', [], 'en'));
});
