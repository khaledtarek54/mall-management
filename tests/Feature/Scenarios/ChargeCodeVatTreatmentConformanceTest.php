<?php

use App\Enums\InvoiceItemType;
use App\Models\ChargeCode;
use App\Support\Vat;
use Database\Seeders\ChargeCodeSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue and the floor must state the SAME tax policy.
 *
 * Taxability lives on `charge_codes.vat_treatment` — an accountant's ruling, saved as a row.
 * `Vat::EXEMPT_TYPES` is the floor underneath it: what an unseeded database bills, so an empty
 * catalogue cannot fall through to the standard rate and charge 14% VAT on base rent.
 *
 * A floor is only safe while it agrees with the thing it stands under. If someone exempts a code in
 * the seeder and forgets the floor, the same charge is taxed differently depending on whether the
 * catalogue happens to be seeded — which is precisely the drift the whole design removes, rebuilt
 * one layer down. This is the same gate `ChargeCodeGlMappingConformanceTest` puts on posting roles,
 * for the same reason.
 */
beforeEach(function () {
    $this->seed(ChargeCodeSeeder::class);
});

it('exempts exactly the codes the floor names', function () {
    $catalogueExempt = ChargeCode::query()
        ->where('vat_treatment', '!=', ChargeCode::VAT_STANDARD)
        ->pluck('code')
        ->all();

    expect($catalogueExempt)->toEqualCanonicalizing(Vat::EXEMPT_TYPES,
        'A code billed at 0 by the catalogue but at the standard rate by the floor (or the reverse) '
        ."is taxed differently depending on whether charge_codes has been seeded.\n"
        .'Catalogue: '.implode(', ', $catalogueExempt)."\n"
        .'Floor:     '.implode(', ', Vat::EXEMPT_TYPES));
});

it('resolves the same rate seeded and unseeded', function () {
    // The property the assertion above only implies. Every seeded code must bill what it would
    // have billed with no catalogue at all — proved by resolving both ways rather than by trusting
    // that the two lists line up.
    $seeded = [];
    foreach (ChargeCode::pluck('code') as $code) {
        $seeded[$code] = Vat::rateForType($code);
    }

    // Emptied at the table, which fires no model event — hence the explicit flush. Doing it the
    // Eloquent way would hide the very hazard the memo has.
    DB::table('charge_codes')->delete();
    ChargeCode::flushLookupCaches();

    foreach ($seeded as $code => $rate) {
        expect(Vat::rateForType($code))->toBe($rate, "{$code} bills {$rate}% seeded and ".Vat::rateForType($code).'% unseeded');
    }
});

it('classifies every code the billing engine has logic for', function () {
    // A code the engine references by name must have a ruling. An unclassified one would inherit
    // the column default (standard-rated) silently, which for a penalty means over-charging the
    // tenant and over-stating VAT payable on the return.
    $classified = ChargeCode::pluck('vat_treatment', 'code')->all();

    foreach (InvoiceItemType::values() as $code) {
        expect(array_key_exists($code, $classified))
            ->toBeTrue("{$code} has no charge-code row, so nothing states its VAT treatment");
        expect(in_array($classified[$code], ChargeCode::VAT_TREATMENTS, true))
            ->toBeTrue("{$code} carries an unknown VAT treatment '{$classified[$code]}'");
    }
});

it('never lets a rate override contradict an untaxed treatment', function () {
    // A rate typed against an exempt code reads as policy and does nothing — `rateForType()`
    // returns 0 for any non-standard treatment. Assert that, so a future refactor that starts
    // honouring the override cannot silently start taxing an exempt supply.
    $code = ChargeCode::where('code', 'base_rent')->first();
    $code->update(['vat_rate_override' => 25]);

    expect(Vat::rateForType('base_rent'))->toBe(Vat::EXEMPT);
});
