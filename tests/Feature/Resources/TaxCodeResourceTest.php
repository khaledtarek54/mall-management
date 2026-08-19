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

/**
 * Updated 2026-08-19, when stamp and schedule tax were COMMISSIONED.
 *
 * This test used to assert that `SCHD_8` and `STAMP_20` were withheld from the pickers, on the
 * grounds that they carried the operator's rates but no GL account for their family. That is no
 * longer true — both now have posting roles and a fresh install ships them active — so the old
 * assertion was pinning a world that had been deliberately left behind, not protecting anything.
 *
 * The property that still matters, and is what this now asserts: **a code is offered only when it
 * can actually bill.** `TaxCode` refuses to activate a taxable code with no rate or no posting
 * role, so an incomplete catalogue is inert rather than a trap — and the picker is keyed on
 * DIRECTION, so the purchases side never appears where a tenant is charged.
 */
it('keeps a code that cannot bill out of the pickers', function () {
    $offered = TaxCode::options(TaxCode::SALES);

    expect($offered)->toHaveKey('VAT_14')
        // Commissioned 2026-08-19: both carry a posting role now, so both bill.
        ->and($offered)->toHaveKey('SCHD_8')
        ->and($offered)->toHaveKey('STAMP_20')
        // The purchases side is not a tax a tenant is charged, and never appears here.
        ->and($offered)->not->toHaveKey('VAT_14_P');

    // The gate itself, proven rather than assumed: retire a commissioned code and it leaves the
    // picker. That is the operator's own lever — a code the accountant stands down must stop
    // being offered without a deploy, and a reseed must never bring it back on.
    TaxCode::where('code', 'SCHD_8')->firstOrFail()->forceFill(['is_active' => false])->save();

    expect(TaxCode::options(TaxCode::SALES))->not->toHaveKey('SCHD_8');
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
