<?php

use App\Enums\InvoiceItemType;
use App\Models\ChargeCode;
use App\Models\TaxCode;
use App\Support\Vat;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\TaxCodeSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\TaxCatalogue;

/**
 * The catalogue and the floor must state the SAME tax policy.
 *
 * Taxability lives on `charge_codes.tax_code` — an accountant's ruling, saved as a row, pointing at
 * the tax in `tax_codes` that holds the rate and the day it came into force. `Vat::EXEMPT_TYPES` is
 * the floor underneath it: what an unseeded database bills, so an empty catalogue cannot fall
 * through to the standard rate and charge 14% VAT on base rent.
 *
 * A floor is only safe while it agrees with the thing it stands under. If someone exempts a code in
 * the seeder and forgets the floor, the same charge is taxed differently depending on whether the
 * catalogue happens to be seeded — which is precisely the drift the whole design removes, rebuilt
 * one layer down. This is the same gate `ChargeCodeGlMappingConformanceTest` puts on posting roles,
 * for the same reason.
 */
beforeEach(function () {
    $this->seed(TaxCodeSeeder::class);
    $this->seed(ChargeCodeSeeder::class);
});

it('exempts exactly the codes the floor names', function () {
    $catalogueExempt = ChargeCode::query()
        ->get(['code', 'tax_code'])
        ->filter(fn (ChargeCode $c) => TaxCode::treatmentOf((string) $c->tax_code) !== TaxCode::STANDARD)
        ->pluck('code')
        ->all();

    expect($catalogueExempt)->toEqualCanonicalizing(Vat::EXEMPT_TYPES,
        'A code billed at 0 by the catalogue but at the standard rate by the floor (or the reverse) '
        ."is taxed differently depending on whether the catalogue has been seeded.\n"
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

    // Emptied at the table, which fires no model event — hence the explicit flushes. Doing it the
    // Eloquent way would hide the very hazard the memos have.
    DB::table('charge_codes')->delete();
    DB::table('tax_rates')->delete();
    DB::table('tax_codes')->delete();
    ChargeCode::flushLookupCaches();
    TaxCode::flushLookupCaches();

    foreach ($seeded as $code => $rate) {
        expect(Vat::rateForType($code))->toBe($rate, "{$code} bills {$rate}% seeded and ".Vat::rateForType($code).'% unseeded');
    }
});

it('classifies every code the billing engine has logic for', function () {
    // A code the engine references by name must have a ruling. An unclassified one falls to the
    // floor, which for a penalty the floor did not happen to name means charging the tenant VAT
    // that is not due and over-stating VAT payable on the return.
    $classified = ChargeCode::pluck('tax_code', 'code')->all();

    foreach (InvoiceItemType::values() as $code) {
        expect(array_key_exists($code, $classified))
            ->toBeTrue("{$code} has no charge-code row, so nothing states which tax it is billed under");
        expect($classified[$code])
            ->not->toBeNull("{$code} names no tax code, so it resolves through the Vat floor rather than the accountant's ruling");
        expect(TaxCode::knows((string) $classified[$code]))
            ->toBeTrue("{$code} names tax code '{$classified[$code]}', which is not in the catalogue");
    }
});

it('never lets a rate contradict an untaxed treatment', function () {
    // A rate entered against an exempt tax reads as policy and does nothing — `rateOn()` returns 0
    // for any non-standard treatment. Assert that, so a future refactor that starts honouring the
    // ladder cannot silently start taxing an exempt supply.
    TaxCatalogue::setRate('VAT_EXEMPT', 25.0);

    expect(Vat::rateForType('base_rent'))->toBe(Vat::EXEMPT)
        ->and(TaxCode::rateOn('VAT_EXEMPT'))->toBe(0.0);
});
