<?php

use App\Filament\Admin\Resources\ChargeCodes\Pages\CreateChargeCode;
use App\Filament\Admin\Resources\ChargeCodes\Pages\EditChargeCode;
use App\Models\ChargeCode;
use App\Models\TaxCode;
use App\Support\Filament\CatalogueAwareSelect;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\TaxCodeSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * DEACTIVATING A TAX CODE MADE EVERY RECORD NAMING IT UNSAVABLE (SW-122).
 *
 * `CatalogueAwareSelect` closed this in 2026-09-02 for the sixteen columns `ValueSets` widens — a
 * saved record keeps the code it already carries, appended to the options and labelled. It could not
 * close it for a TAX code, because it derived the governing catalogue from
 * `ValueSets::catalogueWidenedColumns()` and the tax catalogue is deliberately outside `ValueSets`:
 * `charge_codes.tax_code` holds the accountant's RULING and `tax_codes` holds the RATE, two
 * questions and two homes.
 *
 * The mechanism is upstream and is the same one: `Select::getInValidationRuleValues()` returns `[]`
 * when `getOptionLabel(withDefault: false)` is blank, so `Rule::in([])` refuses EVERY value — the
 * whole form is refused on a field the operator never touched, and nothing on screen says why.
 * `TaxCode::options()` is `->active()`-scoped, and `tax_code` is a column on six tables plus
 * `vendors.withholding_tax_code`, fed by nine pickers.
 *
 * NOTE what is deliberately still open: the Settings screen's withholding default is
 * `Select::make('tax.wht_default_tax_code')` — a dotted name, on a page with no record — so it is
 * neither governed by this map nor fixable by it. That is SW-095, a separate row.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(TaxCodeSeeder::class);
    $this->seed(ChargeCodeSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    // Seeded pointing at VAT_14 — the ordinary shipped arrangement, not a fixture invented for this.
    $this->chargeCode = ChargeCode::query()->where('code', 'service_charge')->sole();

    expect($this->chargeCode->tax_code)->toBe('VAT_14');
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('still saves a charge code whose tax code the accountant has retired', function () {
    // The control: while active it is an ordinary option.
    expect(TaxCode::options(TaxCode::SALES))->toHaveKey('VAT_14');

    TaxCode::query()->where('code', 'VAT_14')->sole()->update(['is_active' => false]);

    // The premise, asserted rather than assumed: the picker really has dropped it.
    expect(TaxCode::options(TaxCode::SALES))->not->toHaveKey('VAT_14');

    asTenant($this->asset, function () {
        Livewire::test(EditChargeCode::class, ['record' => $this->chargeCode->getRouteKey()])
            // The field still reads its own value — the ARRAY form of assertFormSet, because the
            // closure form ignores what the closure returns.
            ->assertFormSet(['tax_code' => 'VAT_14'])
            // …and the record saves, which is the finding.
            ->call('save')
            ->assertHasNoFormErrors();
    });

    // …and the accountant's ruling SURVIVES. A save that silently blanked it would be the worse
    // half of this bug, not the fix: `Vat::rateForType()` then puts the charge on the floor.
    expect($this->chargeCode->fresh()->tax_code)->toBe('VAT_14');
});

it('STILL REFUSES a tax code the picker does not offer, on an existing record', function () {
    // THE ADVERSARIAL CASE. The append is keyed on the STORED value (`getRawOriginal`), never on
    // component state — state is whatever the client last submitted, and appending that would make
    // `Rule::in` accept any string a crafted payload sends. Every other case here proves the
    // carve-out FIRES; this is the one that proves it STOPS.
    TaxCode::query()->where('code', 'VAT_14')->sole()->update(['is_active' => false]);

    asTenant($this->asset, function () {
        Livewire::test(EditChargeCode::class, ['record' => $this->chargeCode->getRouteKey()])
            ->fillForm(['tax_code' => 'NOT_A_TAX_CODE'])
            ->call('save')
            ->assertHasFormErrors(['tax_code']);
    });

    expect($this->chargeCode->fresh()->tax_code)->toBe('VAT_14');
});

it('still refuses a retired tax code on a CREATE form', function () {
    // The other direction, and the one that would make the fix worse than the bug: retiring a code
    // must still stop it being CHOSEN. Only a saved record can be CARRYING one, so the carve-out is
    // keyed on `$record->exists`. Driven through the real create page — a detached component falls
    // through on the container check long before it reaches the record check, so building one here
    // would prove nothing.
    TaxCode::query()->where('code', 'VAT_14')->sole()->update(['is_active' => false]);

    asTenant($this->asset, function () {
        Livewire::test(CreateChargeCode::class)
            ->fillForm([
                'code' => 'key_money',
                // Fixture text only — the form asks for both names and does not care what they say.
                'name_en' => 'Key money',
                'name_ar' => 'Key money',
                'tax_code' => 'VAT_14',
            ])
            ->call('create')
            ->assertHasFormErrors(['tax_code']);
    });

    expect(ChargeCode::query()->where('code', 'key_money')->exists())->toBeFalse();
});

it('leaves an ordinary Select alone', function () {
    // The bail-out is on the field NAME, and the binding is global — `getOptions()` runs about three
    // times per Select per render plus once for validation. A Select whose name is not a governed
    // column must never reach the container, the state or the record.
    $select = CatalogueAwareSelect::make('posting_role')
        ->options(['rent_revenue' => 'Rent revenue']);

    expect($select->getOptions())->toBe(['rent_revenue' => 'Rent revenue']);
});
