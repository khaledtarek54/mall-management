<?php

use App\Filament\Admin\Resources\TaxCodes\Pages\EditTaxCode;
use App\Filament\Admin\Resources\TaxCodes\Pages\ListTaxCodes;
use App\Filament\Admin\Resources\TaxCodes\RelationManagers\RatesRelationManager;
use App\Filament\Admin\Resources\TaxCodes\TaxCodeResource;
use App\Models\TaxCode;
use App\Support\Vat;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\TaxCodeSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The screen the accountant owns.
 *
 * The claim being tested is the one the whole change rests on: **a rate change is a row an
 * accountant types, on the day the law says, with no developer and no deploy** — and it reaches
 * what is billed next without touching what was billed before.
 *
 * Driven through the real Livewire components rather than the models, because the models were
 * already proven in `TaxCatalogueConformanceTest`; what is unproven is that the screen in front of
 * the accountant can actually produce that state.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(TaxCodeSeeder::class);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('renders the catalogue', function () {
    Livewire::test(ListTaxCodes::class)->assertOk();
});

it('lets the accountant schedule next year\'s rate, through the screen', function () {
    // The capability that did not exist while the rate was a settings field: a rise announced in
    // advance, entered once, applying by itself on the day.
    $vat = TaxCode::where('code', Vat::STANDARD_TAX_CODE)->firstOrFail();

    Livewire::test(RatesRelationManager::class, [
        'ownerRecord' => $vat,
        'pageClass' => EditTaxCode::class,
    ])
        ->callTableAction('create', data: [
            'rate' => 17,
            'effective_from' => '2027-01-01',
            'note' => 'Announced in the 2027 budget',
        ])
        ->assertHasNoActionErrors();

    TaxCode::flushLookupCaches();

    expect(Vat::rateForType('service_charge', '2026-12-31'))->toBe(14.0)
        ->and(Vat::rateForType('service_charge', '2027-01-01'))->toBe(17.0);
});

it('refuses two rates for the same code on the same day', function () {
    // Overlapping rungs would make "the rate in force" depend on row order. Refused at the form as
    // well as at the index, so the operator gets a message rather than a database error.
    $vat = TaxCode::where('code', Vat::STANDARD_TAX_CODE)->firstOrFail();

    Livewire::test(RatesRelationManager::class, [
        'ownerRecord' => $vat,
        'pageClass' => EditTaxCode::class,
    ])
        ->callTableAction('create', data: [
            'rate' => 20,
            'effective_from' => TaxCodeSeeder::VAT_STANDARD_FROM,
        ])
        ->assertHasActionErrors(['effective_from']);

    // The control: the rate did not move.
    TaxCode::flushLookupCaches();
    expect(Vat::standardRate())->toBe(14.0);
});

it('keeps a code that cannot bill out of the pickers', function () {
    // The catalogue seeds the operator's whole sheet, most of it not yet commissioned. This is
    // what makes that safe: nothing is offered until it can actually bill.
    $offered = TaxCode::options(TaxCode::SALES);

    expect($offered)->toHaveKey('VAT_14')
        // …and the schedule and stamp codes, which carry the operator's rates but no GL account
        // for their family yet, are not offered until someone wires one.
        ->and($offered)->not->toHaveKey('SCHD_8')
        ->and($offered)->not->toHaveKey('STAMP_20')
        // …nor is the purchases side, which is not a tax a tenant is charged.
        ->and($offered)->not->toHaveKey('VAT_14_P');
});

it('lets an auditor read the catalogue but never change a rate', function () {
    // `viewer` holds every `.view` in the catalogue by design — it is the auditor role, and a tax
    // rate is exactly the kind of thing an auditor should be able to read. What it must not have is
    // the ability to change what every tenant is charged.
    //
    // Asserted on the resource's own predicates rather than by calling an action: `callTableAction`
    // checks visibility first, so a missing gate and a working one both go green.
    $this->actingAs(makeUser('viewer'));

    expect(TaxCodeResource::canAccess())->toBeTrue()
        ->and(TaxCodeResource::canCreate())->toBeFalse()
        ->and(TaxCodeResource::canEdit(TaxCode::where('code', Vat::STANDARD_TAX_CODE)->firstOrFail()))->toBeFalse();

    // The control — the role that owns this screen can do both. Without it, the refusals above
    // would pass just as happily if the resource were unusable for everyone.
    $this->actingAs(makeUser('accounting'));

    expect(TaxCodeResource::canCreate())->toBeTrue()
        ->and(TaxCodeResource::canEdit(TaxCode::where('code', Vat::STANDARD_TAX_CODE)->firstOrFail()))->toBeTrue();
});
