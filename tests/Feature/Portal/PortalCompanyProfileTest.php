<?php

/**
 * A retailer can finally correct their own contact details.
 *
 * The panel already had a profile page, but that one edits the signed-in `TenantUser` — name,
 * email, password. The COMPANY's phone, WhatsApp, address and contact person live on `Tenant`, and
 * nothing outside the admin panel could write them. So the operator maintained them by hand from
 * whatever was said at signing, and they went stale silently — while those are the exact fields the
 * overdue reminders and the collections chase are addressed to.
 *
 * The gate is the interesting part: a **read-only staff login must not be able to redirect where
 * the mall's notices go**. `disabled()` on the field is the UI; `save()` is the gate — a disabled
 * field's value still arrives in the Livewire payload, which is why the refusal is tested by
 * calling `save()` directly rather than by asserting the form looks read-only.
 */

use App\Filament\Portal\Pages\CompanyProfile;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant(['phone' => '+20 100 000 0000', 'whatsapp' => null]);
    makeLease(makeUnit($this->asset), $this->tenant);

    Filament::setCurrentPanel(Filament::getPanel('portal'));
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

/* ---- the control: an account admin can maintain their own details -------- */

it('lets the tenant admin update the contact details', function () {
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: true), 'portal');

    Livewire::test(CompanyProfile::class)
        ->fillForm([
            'phone' => '+20 111 222 3333',
            'whatsapp' => '+20 111 222 4444',
            'contact_person' => 'Mona Adel',
            'address_city' => 'Giza',
        ])
        ->call('save')
        ->assertHasNoErrors();

    $this->tenant->refresh();

    expect($this->tenant->phone)->toBe('+20 111 222 3333')
        ->and($this->tenant->whatsapp)->toBe('+20 111 222 4444')
        ->and($this->tenant->contact_person)->toBe('Mona Adel')
        ->and($this->tenant->address_city)->toBe('Giza');
});

it('loads the current values rather than an empty form', function () {
    // A profile page that opened blank would invite someone to save nulls over what is there.
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: true), 'portal');

    Livewire::test(CompanyProfile::class)
        ->assertFormSet(['phone' => '+20 100 000 0000']);
});

it('stores a cleared field as null, not an empty string', function () {
    // The columns are nullable and the rest of the system tests them with `filled()`. An empty
    // string makes a cleared WhatsApp look present and sends a reminder into nothing.
    $this->tenant->forceFill(['whatsapp' => '+20 100 555 5555'])->save();
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: true), 'portal');

    Livewire::test(CompanyProfile::class)
        ->fillForm(['whatsapp' => '   '])
        ->call('save');

    expect($this->tenant->refresh()->whatsapp)->toBeNull();
});

/* ---- the refusal: a read-only login cannot move the mall's notices ------- */

it('refuses the save for a non-admin staff login, even dispatched directly', function () {
    // `disabled()` is the UI. A disabled field's value still arrives in the payload, so the gate
    // has to be in save() — and proving it means calling save(), not inspecting the form.
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: false), 'portal');

    $before = $this->tenant->phone;

    Livewire::test(CompanyProfile::class)
        ->fillForm(['phone' => '+20 999 999 9999'])
        ->call('save')
        ->assertForbidden();

    expect($this->tenant->refresh()->phone)->toBe($before);
});

/* ---- what this screen must never be able to write ----------------------- */

it('cannot write legal identity or the API credentials', function () {
    // The invoice and the tax filing carry these, and the `Tenant` row is also a Sanctum identity
    // for /api/v1. A crafted payload naming them must not stick — the save takes the whitelist by
    // key rather than mass-assigning what arrived.
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: true), 'portal');

    $before = [
        'name' => $this->tenant->name,
        'tax_id' => $this->tenant->tax_id,
        'email' => $this->tenant->email,
    ];

    Livewire::test(CompanyProfile::class)
        ->fillForm(['phone' => '+20 111 000 0000'])
        // Reach past the form and put forbidden keys straight into the component state.
        ->set('data.name', 'Someone Else LLC')
        ->set('data.tax_id', '999999999')
        ->set('data.email', 'attacker@example.test')
        ->call('save');

    $this->tenant->refresh();

    expect($this->tenant->name)->toBe($before['name'])
        ->and($this->tenant->tax_id)->toBe($before['tax_id'])
        ->and($this->tenant->email)->toBe($before['email'])
        // …and the control: the legitimate field DID save, so this is a whitelist and not a
        // save that quietly does nothing.
        ->and($this->tenant->phone)->toBe('+20 111 000 0000');
});

it('is reachable only where there is a tenant behind the login', function () {
    $this->actingAs(makeTenantUser($this->tenant, isAdmin: true), 'portal');

    expect(CompanyProfile::canAccess())->toBeTrue();
});
