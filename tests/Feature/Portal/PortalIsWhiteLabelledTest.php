<?php

use App\Enums\UnitOwnershipStatus;
use App\Models\UnitOwnership;
use App\Services\TenantStatementPdfService;
use App\Support\Filament\PortalBranding;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
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
 * because branding their portal as one of the three is a claim about the other two. The Statement of
 * Account now answers the same way — it used to take `leases->first()`, so a chain's statement
 * carried one arbitrary mall's letterhead while their portal said Atriom, which is one tenant told
 * two different things by two of our own documents.
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
        // The vendor wordmark IS right here: with no property in play, the software is what the
        // reader is looking at.
        ->and(PortalBranding::logo())->toContain('atriom-logo');
});

it('shows the mall its NAME when it has uploaded no logo, never the vendor wordmark', function () {
    // Filament's `logo.blade.php` renders the brand NAME only in the `@else` — i.e. only when the
    // logo is blank. Returning the platform wordmark for a mall with no logo therefore put ATRIOM in
    // the topbar of a white-labelled portal, which is the default state of every property until
    // somebody uploads a file. It also made this file's own end-to-end assertion a false pass:
    // `toContain('Cairo Festival Mall')` is satisfied by the page <title>.
    $tenant = makeTenant(['name' => 'No Logo Retail']);
    makeLease(makeUnit($this->mall), $tenant, ['status' => 'active']);

    $this->actingAs(makeTenantUser($tenant), 'portal');

    expect($this->mall->logoUrl())->toBeNull()
        ->and(PortalBranding::logo())->toBeNull()
        ->and(PortalBranding::brandName())->toBe('Cairo Festival Mall');

    $html = $this->get('/portal')->assertOk()->getContent();

    expect($html)->not->toContain('atriom-logo-light.svg')
        ->and($html)->not->toContain('atriom-logo-dark.svg');
});

it('memoises the answer it exists for — null', function () {
    // `Container::bound()` is `isset($this->instances[$abstract])`, and `isset()` is FALSE for a
    // stored null. So the memo never registered for a chain tenant or the login page — the two
    // cases this class was written for — and the panel re-ran the query five times per render.
    $tenant = makeTenant(['name' => 'Two Malls Ltd']);
    makeLease(makeUnit($this->mall), $tenant, ['status' => 'active']);
    makeLease(makeUnit($this->other), $tenant, ['status' => 'active']);

    $this->actingAs(makeTenantUser($tenant), 'portal');

    expect(PortalBranding::asset())->toBeNull();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    // The five the panel asks per render.
    PortalBranding::brandName();
    PortalBranding::logo();
    PortalBranding::logo(dark: true);
    PortalBranding::favicon();
    PortalBranding::themeOverride();

    expect($queries)->toBe(0, 'The null answer is being re-derived on every call.');
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
    // A REAL logo, so the assertion below is about the topbar and not about the <title> — which
    // contains the mall's name either way, and is what made the first version of this a false pass.
    $this->mall->addMediaFromString('<svg xmlns="http://www.w3.org/2000/svg"/>')
        ->usingFileName('cfm-logo.svg')
        ->toMediaCollection('logo');

    PortalBranding::forget();

    $html = $this->get('/portal')->assertOk()->getContent();

    expect($html)->toContain('cfm-logo')
        ->and($html)->toContain('--primary-500: #0f766e')
        ->and($html)->not->toContain('atriom-logo-light.svg')
        ->and($html)->not->toContain('Atriom · Tenant Portal');
});

it('says the platform name in Arabic too', function () {
    // The literal it replaced was English on an Arabic page. Checked with `fallback: false`, because
    // `Lang::has($key, 'ar')` falls back to English and would pass for a key that exists only in EN.
    expect(Lang::has('portal.brand', 'ar', false))->toBeTrue()
        ->and(__('portal.brand', [], 'ar'))->not->toBe(__('portal.brand', [], 'en'));
});

it('gives a chain the operator letterhead on their statement, not one arbitrary mall', function () {
    // The same question, one document along, answered differently until now: the Statement of
    // Account took `leases->first()?->unit?->asset`, so a tenant leasing in three malls got ONE of
    // them on the letterhead of a document listing all three — while their portal said Atriom.
    $chain = makeTenant(['name' => 'Three Malls Ltd']);
    $here = makeLease(makeUnit($this->mall), $chain, ['status' => 'active']);
    $there = makeLease(makeUnit($this->other), $chain, ['status' => 'active']);

    makeInvoice($here);
    makeInvoice($there);

    $statements = app(TenantStatementPdfService::class);

    expect($statements->data($chain->fresh())['asset'])->toBeNull();

    // The control: a single-mall tenant still gets their mall's letterhead, so the rule is about
    // ambiguity and not about having removed the feature.
    $single = makeTenant(['name' => 'One Mall Ltd']);
    makeInvoice(makeLease(makeUnit($this->mall), $single, ['status' => 'active']));

    expect($statements->data($single->fresh())['asset']?->id)->toBe($this->mall->id);
});

it('keeps the letterhead for a single-mall tenant who has not been billed yet', function () {
    // The exactly-one rule reads the INVOICES, and a tenant who has just signed has none. Falling
    // silent for them would be a regression dressed up as the new rule: the rule is about
    // AMBIGUITY, not about having fewer documents. Their lease still says which mall this is.
    $fresh = makeTenant(['name' => 'Just Signed Ltd']);
    makeLease(makeUnit($this->mall), $fresh, ['status' => 'active']);

    $view = app(TenantStatementPdfService::class)->data($fresh->fresh());

    expect($view['asset']?->id)->toBe($this->mall->id);
});

it('keeps the letterhead for a unit OWNER who holds no lease at all', function () {
    // Module 37: a unit owner IS a `tenants` row, pays صيانة, and may never sign a lease. The
    // fallback looked only at leases — so the party the comment above cites BY NAME was the one it
    // could not answer for, right up until their first assessment was raised.
    $owner = makeTenant(['name' => 'Bought Two Floors Ltd']);

    UnitOwnership::create([
        'tenant_id' => $owner->id,
        'unit_id' => makeUnit($this->mall)->id,
        'asset_id' => $this->mall->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'purchase_date' => now()->subYear()->toDateString(),
    ]);

    $view = app(TenantStatementPdfService::class)->data($owner->fresh());

    expect($view['asset']?->id)->toBe($this->mall->id);
});

it('keeps the letterhead for a tenant who LEFT one mall for another, rather than counting the old one', function () {
    // Counting terminal leases made an ex-tenant of mall A now trading in mall B resolve to TWO
    // assets — so the fallback went quiet for a tenant who is unambiguously in one place. Only live
    // agreements count, which is the same reading of "where does this tenant stand" the rest of the
    // statement uses.
    $moved = makeTenant(['name' => 'Moved Across Town Ltd']);
    makeLease(makeUnit($this->other), $moved, ['status' => 'terminated']);
    makeLease(makeUnit($this->mall), $moved, ['status' => 'active']);

    $view = app(TenantStatementPdfService::class)->data($moved->fresh());

    expect($view['asset']?->id)->toBe($this->mall->id);
});

it('keeps the letterhead for an owner who SOLD in one mall and bought in another', function () {
    // The terminal-lease filter had a twin one relation over that the first cut missed: the
    // ownership union was unfiltered, so a `transferred` (sold-on) unit in mall A plus a live one in
    // mall B resolved to two assets and dropped the letterhead for someone unambiguously in one
    // place. `handed_over` is the predicate — the SAME one `PortalBranding` uses, because a
    // tenant's portal chrome and their statement letterhead disagreeing is the exact failure the
    // exactly-one-mall rule exists to prevent.
    $owner = makeTenant(['name' => 'Sold Up And Moved Ltd']);

    UnitOwnership::create([
        'tenant_id' => $owner->id,
        'unit_id' => makeUnit($this->other)->id,
        'asset_id' => $this->other->id,
        'status' => UnitOwnershipStatus::Transferred->value,
        'purchase_date' => now()->subYears(3)->toDateString(),
    ]);

    UnitOwnership::create([
        'tenant_id' => $owner->id,
        'unit_id' => makeUnit($this->mall)->id,
        'asset_id' => $this->mall->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'purchase_date' => now()->subMonth()->toDateString(),
    ]);

    $view = app(TenantStatementPdfService::class)->data($owner->fresh());

    expect($view['asset']?->id)->toBe($this->mall->id);
});
