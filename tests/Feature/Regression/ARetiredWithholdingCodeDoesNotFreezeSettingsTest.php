<?php

use App\Filament\Admin\Pages\Settings;
use App\Models\TaxCode;
use App\Models\TaxRate;
use App\Settings\BillingSettings;
use App\Settings\TaxSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * Retiring a tax code must not brick the settings screen (SW-095).
 *
 * `TaxCode::options()` offers ACTIVE rows only, which is exactly what retiring a code means — and
 * Filament derives a Select's `Rule::in` from the options it resolved. Measured 2026-09-03 on a
 * mounted schema: state `WH_3_P`, options without it, rules `['nullable', Rule::in([])]` — an EMPTY
 * in-list, which rejects every value including the one already stored.
 *
 * On /admin/settings that is not one field. All eight tabs are ONE schema and `save()` calls
 * `$this->form->getState()`, which validates the whole form — so switching off the withholding code
 * the screen names stopped billing, accounting, SLA, payroll, integrations and housekeeping settings
 * from saving too, silently, on a field nobody had touched.
 *
 * This is the other half of the 2026-09-02 `CatalogueAwareSelect` fix. That closes it for RECORD
 * forms by keying on the record's table; a settings page has no record, so the seam here is an
 * explicit `keep` on the catalogue's own options method — the shape `FailureCode::options()` has had
 * since the failure-code register shipped.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('super_admin'));

    // Two withholding codes, so retiring one still leaves the picker something real to offer and
    // the assertions below are about the RETIRED code rather than about an empty dropdown.
    foreach ([['WH_KEEP', 'Withholding -1%', -1.0], ['WH_RETIRED', 'Withholding -3%', -3.0]] as [$code, $name, $rate]) {
        $row = TaxCode::create([
            'code' => $code,
            'name_en' => $name,
            'name_ar' => 'خصم وتحصيل تحت حساب الضريبة',
            'family' => TaxCode::FAMILY_WITHHOLDING,
            'direction' => TaxCode::PURCHASES,
            'treatment' => TaxCode::STANDARD,
            'invoice_label' => 'WH -1%',
            'posting_role' => 'withholding_tax_payable',
            'is_active' => false,
            'sort_order' => 10,
        ]);

        // A standard-rated code refuses to activate without a rung it could bill on — the same
        // order `TaxCodeSeeder` does it in.
        TaxRate::create(['tax_code_id' => $row->id, 'rate' => $rate, 'effective_from' => '2020-01-01']);
        $row->update(['is_active' => true]);
    }
});

it('goes on saving every other setting after the named code is retired', function () {
    // The operator names a code. The CONTROL: this must save cleanly while the code is active, or
    // the refusal below would pass for the wrong reason.
    Livewire::test(Settings::class)
        ->set('data.tax.wht_default_tax_code', 'WH_RETIRED')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(TaxSettings::class)->wht_default_tax_code)->toBe('WH_RETIRED');

    // …and later retires it at /admin/tax-codes, which is an ordinary, supported act.
    TaxCode::query()->where('code', 'WH_RETIRED')->first()->update(['is_active' => false]);

    // Now an unrelated setting on an unrelated tab. This is the defect: it used to be refused.
    Livewire::test(Settings::class)
        ->set('data.billing.late_fee_percent', 7)
        ->call('save')
        ->assertHasNoErrors();

    expect((float) app(BillingSettings::class)->late_fee_percent)->toBe(7.0)
        ->and(app(TaxSettings::class)->wht_default_tax_code)->toBe('WH_RETIRED');

    // Offered, and MARKED — the retired code is history, not a suggestion.
    $offered = TaxCode::options(TaxCode::PURCHASES, families: [TaxCode::FAMILY_WITHHOLDING], keep: 'WH_RETIRED');

    expect($offered)->toHaveKey('WH_RETIRED')
        ->and($offered['WH_RETIRED'])->toEndWith(' ⚠');
});

it('offers a retired code only to whoever is already carrying it', function () {
    TaxCode::query()->where('code', 'WH_RETIRED')->first()->update(['is_active' => false]);

    // Nothing names it, so nothing offers it: appending on `keep` must not become "show the retired
    // ones too", which would be the opposite of what retiring a code means.
    $offered = TaxCode::options(TaxCode::PURCHASES, families: [TaxCode::FAMILY_WITHHOLDING]);

    expect($offered)->toHaveKey('WH_KEEP')
        ->and($offered)->not->toHaveKey('WH_RETIRED');
});

it('still refuses a code the catalogue never offered', function () {
    // The security half. `keep` widens the in-rule by exactly one PERSISTED value; it must not make
    // the field accept whatever the payload carries. Every mutation of the fix so far proves the
    // carve-out FIRES — this is the one that proves it still STOPS something.
    Livewire::test(Settings::class)
        ->set('data.tax.wht_default_tax_code', 'WH_RETIRED')
        ->call('save')
        ->assertHasNoErrors();

    TaxCode::query()->where('code', 'WH_RETIRED')->first()->update(['is_active' => false]);

    Livewire::test(Settings::class)
        ->set('data.tax.wht_default_tax_code', 'WH_NOT_A_CODE')
        ->call('save')
        ->assertHasErrors('data.tax.wht_default_tax_code');

    expect(app(TaxSettings::class)->wht_default_tax_code)->toBe('WH_RETIRED');

    // The control beside the refusal: an ACTIVE code still saves, so the assertion above is not
    // passing because the field refuses everything.
    Livewire::test(Settings::class)
        ->set('data.tax.wht_default_tax_code', 'WH_KEEP')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(TaxSettings::class)->wht_default_tax_code)->toBe('WH_KEEP');
});
