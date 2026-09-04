<?php

use App\Filament\Admin\Resources\TaxCodes\Pages\EditTaxCode;
use App\Filament\Admin\Resources\TaxCodes\RelationManagers\RatesRelationManager;
use App\Models\TaxCode;
use App\Support\Vat;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\TaxCodeSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A withholding rate is entered the way the operator's own sheet writes it — negative (SW-204).
 *
 * `tax_rates.rate` is SIGNED by convention: withholding reads "WH -1%" because the tax comes OFF
 * what is paid to a supplier rather than being added to it. `TaxCodeSeeder` writes the rungs that
 * way and `TaxCatalogueConformanceTest` asserts they stay that way — and the one form that can
 * write that table clamped its input at zero, so the screen refused the very rows the install had
 * just seeded.
 *
 * Measured on the dev database 2026-09-04: 8 withholding rungs, every one of them negative, and not
 * one of them re-savable. It failed in the worst available way — as *invalid* on the rate field, on
 * an edit where the operator had changed only the note — and it made a rate revision, which is the
 * entire reason the ladder is dated, impossible to enter for the one family whose rates Egypt
 * revises by decree.
 *
 * Driven through the real relation manager, because the model was never wrong: the bug was the
 * screen, and a test on `TaxRate::create()` passes just as happily on the broken build.
 *
 * No file-scope helper here on purpose — two test files declaring one name is a FATAL redeclaration
 * that exits the whole suite 255 with no output, and this tree is edited by several sessions at
 * once. Four mounts, written out.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(TaxCodeSeeder::class);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('lets an accountant re-save a withholding rung the catalogue stores negative', function () {
    $wh = TaxCode::where('code', 'WH_1')->firstOrFail();
    $rung = $wh->rates()->firstOrFail();

    // The premise, asserted before anything is reported on it: the seeded ladder really is stored
    // negative. Without this the test could go green against a catalogue that had quietly stopped
    // being the shape under test.
    expect((float) $rung->rate)->toBe(-1.0);

    Livewire::test(RatesRelationManager::class, [
        'ownerRecord' => $wh,
        'pageClass' => EditTaxCode::class,
    ])
        // Only the note is touched. The rate arrives in the payload because the form filled it from
        // the record — which is exactly how an operator met this refusal.
        ->callTableAction('edit', $rung, data: ['note' => 'Re-confirmed with the accountant'])
        ->assertHasNoActionErrors();

    $rung->refresh();

    expect((float) $rung->rate)->toBe(-1.0)
        ->and($rung->note)->toBe('Re-confirmed with the accountant');
});

it('lets an accountant schedule next year\'s withholding rate', function () {
    $wh = TaxCode::where('code', 'WH_1')->firstOrFail();

    Livewire::test(RatesRelationManager::class, [
        'ownerRecord' => $wh,
        'pageClass' => EditTaxCode::class,
    ])
        ->callTableAction('create', data: [
            'rate' => -2,
            'effective_from' => '2027-01-01',
            'note' => 'Ministerial decree',
        ])
        ->assertHasNoActionErrors();

    TaxCode::flushLookupCaches();

    // The rung reaches what is resolved NEXT and nothing that was resolved before it.
    expect(TaxCode::rateOn('WH_1', '2026-12-31'))->toBe(-1.0)
        ->and(TaxCode::rateOn('WH_1', '2027-01-01'))->toBe(-2.0);
});

it('refuses a withholding rung typed as a positive', function () {
    // The other half of the bound, and the reason the fix is not simply a wider floor: the sign IS
    // the classification. A withholding rung entered as +2 would read as a tax CHARGED, which
    // `TaxCatalogueConformanceTest` asserts no row is.
    $wh = TaxCode::where('code', 'WH_1')->firstOrFail();

    Livewire::test(RatesRelationManager::class, [
        'ownerRecord' => $wh,
        'pageClass' => EditTaxCode::class,
    ])
        ->callTableAction('create', data: [
            'rate' => 2,
            'effective_from' => '2028-01-01',
        ])
        ->assertHasActionErrors(['rate']);

    TaxCode::flushLookupCaches();

    // Refused, not silently corrected: nothing was written.
    expect($wh->rates()->count())->toBe(1)
        ->and(TaxCode::rateOn('WH_1', '2028-01-01'))->toBe(-1.0);
});

it('still refuses a negative rate on a tax that is charged, and still takes a positive one', function () {
    // The control that stops the fix becoming a widening. Only withholding is signed; a negative
    // VAT rung would bill every tenant a credit.
    $vat = TaxCode::where('code', Vat::STANDARD_TAX_CODE)->firstOrFail();

    Livewire::test(RatesRelationManager::class, [
        'ownerRecord' => $vat,
        'pageClass' => EditTaxCode::class,
    ])
        ->callTableAction('create', data: [
            'rate' => -5,
            'effective_from' => '2027-01-01',
        ])
        ->assertHasActionErrors(['rate']);

    Livewire::test(RatesRelationManager::class, [
        'ownerRecord' => $vat,
        'pageClass' => EditTaxCode::class,
    ])
        ->callTableAction('create', data: [
            'rate' => 17,
            'effective_from' => '2027-01-01',
        ])
        ->assertHasNoActionErrors();

    TaxCode::flushLookupCaches();

    expect(TaxCode::rateOn(Vat::STANDARD_TAX_CODE, '2027-01-01'))->toBe(17.0);
});
