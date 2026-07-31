<?php

/**
 * The buyer address on an Egyptian e-invoice must be the buyer's.
 *
 * ETA files the receiver address in PARTS — governorate, city, street, building
 * number — and validates them. `tenants` only ever had a freeform `address`
 * textarea, so EtaJsonBuilder filled the parts with constants:
 *
 *     'governate'      => 'Giza'
 *     'regionCity'     => '6th of October City'
 *     'buildingNumber' => '1'
 *     'street'         => the tenant's whole freeform address
 *
 * So every document filed for a tenant outside 6th of October declared the wrong
 * buyer address to the tax authority, and the building number was wrong for all
 * of them. Nothing caught it because ETA is still in mock mode and the fake
 * endpoint accepts anything — the first real filing would have been the test.
 *
 * The fix refuses rather than guesses. Parsing a freeform bilingual address into
 * parts would put invented data on a legal tax document, which is worse than the
 * constants; a refusal names the tenant so someone fills four fields once. This
 * mirrors the tax_id guard that was already there.
 */

use App\Models\Tenant;
use App\Services\Eta\EtaJsonBuilder;
use App\Support\EgyptGovernorates;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    $this->tenant = makeTenant([
        'type' => 'company',
        'name' => 'Zara Egypt',
        'legal_name' => 'Zara Egypt LLC',
        'tax_id' => '123456789',
        'address' => 'Shop B-12, Atriom Walk, New Cairo',
        'address_governorate' => 'Cairo',
        'address_city' => 'New Cairo',
        'address_street' => 'North 90th Street',
        'address_building_number' => '12',
    ]);

    $this->invoice = makeInvoice(makeLease(makeUnit($this->asset), $this->tenant));
});

it('files the tenant\'s own governorate and city, not a constant', function () {
    $json = app(EtaJsonBuilder::class)->build($this->invoice->fresh());

    expect($json['receiver']['address']['governate'])->toBe('Cairo')
        ->and($json['receiver']['address']['regionCity'])->toBe('New Cairo')
        ->and($json['receiver']['address']['street'])->toBe('North 90th Street')
        ->and($json['receiver']['address']['buildingNumber'])->toBe('12');
});

it('never files the old hardcoded address for a tenant elsewhere', function () {
    // The specific regression: a tenant in Alexandria used to be filed as Giza.
    $this->tenant->update([
        'address_governorate' => 'Alexandria',
        'address_city' => 'Smouha',
        'address_street' => 'Victor Emanuel Square',
        'address_building_number' => '5',
    ]);

    $address = app(EtaJsonBuilder::class)->build($this->invoice->fresh())['receiver']['address'];

    expect($address['governate'])->not->toBe('Giza')
        ->and($address['regionCity'])->not->toBe('6th of October City')
        ->and($address['buildingNumber'])->not->toBe('1')
        ->and($address['governate'])->toBe('Alexandria');
});

it('refuses to build a business invoice with an incomplete address', function () {
    // Refusing is the point. Filing a guess is what this replaces.
    $this->tenant->update(['address_governorate' => null]);

    expect(fn () => app(EtaJsonBuilder::class)->build($this->invoice->fresh()))
        ->toThrow(RuntimeException::class);
});

it('names the tenant and the missing parts, so the refusal is actionable', function () {
    // A refusal nobody can act on just moves the problem. The message has to say
    // WHICH tenant and WHICH fields.
    $this->tenant->update([
        'address_governorate' => null,
        'address_building_number' => null,
    ]);

    try {
        app(EtaJsonBuilder::class)->build($this->invoice->fresh());
        $this->fail('Expected a refusal.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())
            ->toContain('Zara Egypt')
            ->toContain('governorate')
            ->toContain('building number')
            // ...and NOT the fields that are actually filled.
            ->not->toContain('street');
    }
});

it('still files an INDIVIDUAL tenant without the tax address', function () {
    // A person receiver is not address-validated the same way, and individuals are
    // not required to be filed at all. Requiring four extra fields from them would
    // block a submission for no gain — the guard is scoped to businesses, exactly
    // like the tax_id guard beside it.
    $person = makeTenant([
        'type' => 'individual',
        'name' => 'Ahmed Hassan',
        'tax_id' => null,
        'address' => '14 Nile Street, Maadi',
        'address_governorate' => null,
        'address_city' => null,
        'address_street' => null,
        'address_building_number' => null,
    ]);

    $invoice = makeInvoice(makeLease(makeUnit($this->asset), $person));

    $json = app(EtaJsonBuilder::class)->build($invoice->fresh());

    expect($json['receiver']['type'])->toBe('P')
        // Falls back to the freeform address rather than an empty string.
        ->and($json['receiver']['address']['street'])->toBe('14 Nile Street, Maadi');
});

it('keeps ETA\'s own spelling of the governorate key', function () {
    // ETA's wire format spells it "governate". It is their contract, not ours to
    // correct — renaming it to "governorate" would silently fail validation.
    $json = app(EtaJsonBuilder::class)->build($this->invoice->fresh());

    expect($json['receiver']['address'])->toHaveKey('governate')
        ->and($json['receiver']['address'])->not->toHaveKey('governorate');
});

it('offers only governorates ETA recognises', function () {
    // A free-text box would produce "Cairo", "cairo", "القاهرة" and "Cairo
    // Governorate" across a few hundred tenants, and ETA accepts only some of those.
    $values = EgyptGovernorates::values();

    expect($values)->toHaveCount(27)
        ->and($values)->toContain('Cairo', 'Giza', 'Alexandria', 'South Sinai');

    // The filed value is the English key regardless of the operator's UI language.
    App::setLocale('ar');
    expect(array_keys(EgyptGovernorates::options()))->toBe($values);
});

it('refuses an invoice whose tenant has been archived', function () {
    // invoices.tenant_id is NOT NULL, but Tenant soft-deletes and the relation
    // applies that scope — so an archived tenant resolves to null here. The old
    // code filed the document anyway: buyer "Unknown", tax id 000000000, and the
    // hardcoded Giza address. A tax document naming a buyer that does not exist.
    trashBypassingDeletionPolicy($this->tenant);

    expect(fn () => app(EtaJsonBuilder::class)->build($this->invoice->fresh()))
        ->toThrow(RuntimeException::class);
});
