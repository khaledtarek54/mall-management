<?php

use App\Enums\TenantRequestType;
use App\Models\TenantRequestSubcategory;
use App\Services\DisputeInvoiceItemService;
use Database\Seeders\TenantRequestSubcategorySeeder;

/**
 * Three things the app had to guess at, and one it could not reach at all.
 */
beforeEach(function () {
    $this->tenant = makeTenant();
});

it('publishes the sub-category catalogue, so the app is not one release behind the operator', function () {
    $this->seed(TenantRequestSubcategorySeeder::class);

    $data = $this->getJson('/api/v1/me/request-types', apiHeaders($this->tenant))
        ->assertOk()->json('data');

    $maintenance = collect($data['types'])->firstWhere('code', 'maintenance');
    $codes = collect($maintenance['subcategories'])->pluck('code');

    // The seven a tenant could not report until EG-14 — each one a trade the operator dispatches
    // every week. A hardcoded client list still has none of them.
    expect($codes)->toContain('elevator', 'generator', 'fire_safety', 'pest_control', 'security', 'landscaping', 'waste')
        // …alongside the originals.
        ->toContain('electrical', 'plumbing', 'hvac');
});

it('follows the operator when they add a sub-category, with no deploy on either side', function () {
    $this->seed(TenantRequestSubcategorySeeder::class);

    TenantRequestSubcategory::create([
        'request_type' => 'maintenance',
        'code' => 'glazing',
        'name_en' => 'Glazing',
        'name_ar' => 'زجاج',
        'is_active' => true,
        'sort_order' => 99,
    ]);

    $data = $this->getJson('/api/v1/me/request-types', apiHeaders($this->tenant))->assertOk()->json('data');
    $maintenance = collect($data['types'])->firstWhere('code', 'maintenance');
    $glazing = collect($maintenance['subcategories'])->firstWhere('code', 'glazing');

    // The whole point of the endpoint: this is a row, and the app reads rows.
    expect($glazing)->not->toBeNull()
        ->and($glazing['label'])->toBe('Glazing')
        // An operator-added code has no lang key. `IsCodeCatalogue` renders the ROW's own name
        // rather than falling through to `admin.enums.…glazing`, which is what the tenant would
        // otherwise read in the picker.
        ->and($glazing['labelAr'])->not->toStartWith('admin.');

    // And it is genuinely accepted by the create endpoint — the catalogue the picker reads and the
    // one the validator reads are the same catalogue.
    $this->postJson('/api/v1/me/requests', [
        'requestType' => 'maintenance', 'title' => 'Cracked shopfront',
        'description' => '...', 'category' => 'glazing',
        'unitId' => makeLease(makeUnit(makeAsset()), $this->tenant)->unit_id,
    ], apiHeaders($this->tenant))->assertCreated();
});

it('ships both languages so switching locale needs no round trip', function () {
    $this->seed(TenantRequestSubcategorySeeder::class);

    // Asked in English…
    $en = $this->getJson('/api/v1/me/request-types', apiHeaders($this->tenant) + ['Accept-Language' => 'en'])
        ->assertOk()->json('data.types');
    // …and in Arabic. Both responses must carry BOTH labels, or a cached catalogue goes
    // monolingual the moment the reader changes their mind.
    $ar = $this->getJson('/api/v1/me/request-types', apiHeaders($this->tenant) + ['Accept-Language' => 'ar'])
        ->assertOk()->json('data.types');

    $enMaint = collect($en)->firstWhere('code', 'maintenance');
    $arMaint = collect($ar)->firstWhere('code', 'maintenance');

    expect($enMaint['label'])->toBe($arMaint['label'])
        ->and($enMaint['labelAr'])->toBe($arMaint['labelAr'])
        // …and they are genuinely different words, not the same string twice — the failure that
        // makes a bilingual payload look right and read as English everywhere.
        ->and($enMaint['label'])->not->toBe($enMaint['labelAr']);
});

it('says which types are QUESTIONS, so a rejection is never rendered as an approval', function () {
    $types = collect($this->getJson('/api/v1/me/request-types', apiHeaders($this->tenant))->json('data.types'));

    // The three where the tenant is asking for permission or for a thing. Without this the client
    // has only the lifecycle, and `resolved` read as "Approved" on a permit a staff member had
    // REFUSED — the defect `requires_decision` was shipped for.
    foreach (['permit', 'access', 'document'] as $code) {
        expect($types->firstWhere('code', $code)['requiresDecision'])->toBeTrue();
    }

    expect($types->firstWhere('code', 'maintenance')['requiresDecision'])->toBeFalse();
});

it('sends no sub-categories for a type that has none', function () {
    $types = collect($this->getJson('/api/v1/me/request-types', apiHeaders($this->tenant))->json('data.types'));

    // `inquiry` and `billing` define none, and the create endpoint marks `category` *prohibited*
    // for them. An empty array is the answer; the client must render no picker rather than an
    // empty one.
    expect($types->firstWhere('code', 'inquiry')['subcategories'])->toBe([])
        ->and($types->firstWhere('code', 'billing')['subcategories'])->toBe([]);
});

it('shows which invoice LINE is disputed, and what was said about it', function () {
    $lease = makeLease(makeUnit(makeAsset()), $this->tenant);
    $invoice = makeInvoice($lease, ['status' => 'issued']);
    $item = $invoice->items()->create([
        'description' => 'Service charge - August 2026',
        'type' => 'service_charge',
        'amount' => 2000, 'vat_rate' => 14, 'vat_amount' => 280, 'total' => 2280,
    ]);

    app(DisputeInvoiceItemService::class)->dispute($item, 'Common area was closed for three weeks.');

    $data = $this->getJson("/api/v1/me/invoices/{$invoice->id}", apiHeaders($this->tenant))
        ->assertOk()->json('data');

    // `invoices.status` said only that SOMETHING was being argued about. The portal has rendered
    // the line's own reason all along; the app had neither.
    expect($data['items'][0]['disputedReason'])->toBe('Common area was closed for three weeks.')
        ->and($data['items'][0]['disputedAt'])->not->toBeNull();
});

it('lets the tenant set the language they are WRITTEN to in', function () {
    // `preferredLocale()` has read this column since 2026-08-12 and no screen or endpoint has ever
    // written it — so it answered null for every tenant, and the app's language toggle reached the
    // JSON and the PDFs but never push, e-mail, or an invoice the operator sends.
    $this->patchJson('/api/v1/me', ['locale' => 'ar'], apiHeaders($this->tenant))
        ->assertOk()
        ->assertJsonPath('data.locale', 'ar');

    expect($this->tenant->fresh()->preferredLocale())->toBe('ar');
});

it('refuses a locale nothing can render', function () {
    // An unsupported locale does not throw at render time — `__()` falls silently through to the
    // fallback — so an unvalidated value leaves the column looking set while every document arrives
    // in English. Refused at the write, which is the only place it is still visible.
    $this->patchJson('/api/v1/me', ['locale' => 'fr-CA'], apiHeaders($this->tenant))
        ->assertStatus(422)
        ->assertJsonPath('errors.locale.0', fn (string $m) => $m !== '');

    expect($this->tenant->fresh()->locale)->toBeNull();
});
