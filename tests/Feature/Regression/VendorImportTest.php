<?php

use App\Filament\Imports\VendorImporter;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\TaxCodeSeeder;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\ValidationException;

/**
 * The supplier register has to be loadable at cut-over, and re-loadable without forking it.
 *
 * Fourth importer after tenants, units and leases — and the identity question is the one that bit
 * every one of them. Re-running an import is the NORMAL response to a partial one, so a row has to
 * be recognisable on a second pass. Keying on `name` would fork "Cairo HVAC Co." and "Cairo HVAC Co"
 * into two suppliers, each accumulating its own bills, contracts and compliance documents; once
 * either has history `RefusesDeletionWhenReferenced` correctly refuses to delete it, and the two
 * cannot be merged.
 *
 * Deliberately seeder-free: everything here builds its own fixtures, so the file runs on a bare
 * migrated database.
 */
beforeEach(function () {
    $this->import = Import::create([
        'completed_at' => null,
        'file_name' => 'vendors.csv',
        'file_path' => 'vendors.csv',
        'importer' => VendorImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => User::factory()->create()->id,
    ]);
});

function importVendorRow(array $row): void
{
    $columnMap = collect(array_keys($row))->mapWithKeys(fn ($k) => [$k => $k])->all();

    (new VendorImporter(test()->import, $columnMap, []))($row);
}

it('loads a supplier from a row', function () {
    importVendorRow([
        'name' => 'Cairo HVAC Co.',
        'type' => 'contractor',
        'tax_id' => '512-874-336',
        'email' => 'ops@cairohvac.test',
    ]);

    $vendor = Vendor::sole();

    expect($vendor->name)->toBe('Cairo HVAC Co.')
        ->and($vendor->type)->toBe('contractor')
        // Derived by the model, never taken from the file — two suppliers must not collide on a
        // column the model guarantees is unique.
        ->and($vendor->slug)->not->toBeEmpty();
});

it('recognises the same supplier by tax registration on re-import', function () {
    importVendorRow(['name' => 'Cairo HVAC Co.', 'tax_id' => '512-874-336']);
    importVendorRow(['name' => 'Cairo HVAC Company', 'tax_id' => '512-874-336']);

    expect(Vendor::count())->toBe(1)
        ->and(Vendor::sole()->name)->toBe('Cairo HVAC Company');
});

it('matches a TRN written with and without dashes', function () {
    // The operator's file and their existing record will not agree on punctuation, and a supplier
    // forked on a hyphen is a supplier forked.
    Vendor::create(['name' => 'Nile Security', 'tax_id' => '512874336', 'status' => 'active']);

    importVendorRow(['name' => 'Nile Security Services', 'tax_id' => '512-874-336']);

    expect(Vendor::count())->toBe(1)
        ->and(Vendor::sole()->name)->toBe('Nile Security Services');
});

it('falls back to email when there is no tax registration', function () {
    importVendorRow(['name' => 'Small Trader', 'email' => 'hi@small.test']);
    importVendorRow(['name' => 'Small Trader Est.', 'email' => 'hi@small.test']);

    expect(Vendor::count())->toBe(1);
});

it('creates separate suppliers for genuinely different companies', function () {
    // The paired control. Deduplication must not collapse two real suppliers into one — that would
    // be a worse failure than the duplicates it prevents, and silent.
    importVendorRow(['name' => 'Alpha Supplies', 'tax_id' => '111-111-111']);
    importVendorRow(['name' => 'Beta Supplies', 'tax_id' => '222-222-222']);

    expect(Vendor::count())->toBe(2);
});

it('keeps a blank withholding code NULL rather than exempt', function () {
    // The distinction the whole WHT feature rests on: no code = "no agreed nature, use the
    // portfolio default"; exempt = "this supplier is outside withholding". Importing a blank cell
    // as exempt would silently exempt every supplier on the register, and nothing would be withheld
    // from any of them. It used to be null-versus-zero in ONE column, which is why the two are now
    // two columns — a magic zero is a distinction nobody can see in a spreadsheet.
    importVendorRow(['name' => 'Default Rate Co', 'tax_id' => '333-333-333', 'withholding_tax_code' => '']);

    expect(Vendor::sole()->withholding_tax_code)->toBeNull()
        ->and(Vendor::sole()->withholding_exempt)->toBeFalse();
});

it('keeps an explicit exemption — outside withholding is a real answer', function () {
    importVendorRow(['name' => 'Exempt Co', 'tax_id' => '444-444-444', 'withholding_exempt' => '1']);

    expect(Vendor::sole()->withholding_exempt)->toBeTrue();
});

it('refuses a withholding rate the operator\'s catalogue does not contain', function () {
    // The gain from importing a CODE rather than a percentage. A spreadsheet carrying "2" was
    // previously accepted and quietly withheld 2% from that supplier for ever — a rate the
    // operator's own tax sheet does not list, on money leaving the bank.
    test()->seed(TaxCodeSeeder::class);

    expect(fn () => importVendorRow([
        'name' => 'Invented Rate Co', 'tax_id' => '666-666-666', 'withholding_tax_code' => '2',
    ]))->toThrow(ValidationException::class);

    expect(Vendor::count())->toBe(0);
});

it('rejects a type the column cannot store', function () {
    // `vendors.type` is validated against the exact set the column accepts (App\Support\ValueSets),
    // so the operator gets "The selected type is invalid" on that row — rather than the INSERT
    // failing later as an opaque failed row with no reason attached to it.
    expect(fn () => importVendorRow([
        'name' => 'Bad Type Co', 'tax_id' => '555-555-555', 'type' => 'freelancer',
    ]))->toThrow(ValidationException::class);

    expect(Vendor::count())->toBe(0);
});

it('rejects a malformed tax registration', function () {
    // It is the identity key, so a malformed one does not merely store badly — it splits the
    // supplier on the next pass.
    expect(fn () => importVendorRow(['name' => 'Bad TRN Co', 'tax_id' => 'not-a-trn']))
        ->toThrow(ValidationException::class);

    expect(Vendor::count())->toBe(0);
});

it('declares its columns and withholds the ones the model owns', function () {
    $columns = collect(VendorImporter::getColumns())->map(fn ($c) => $c->getName())->all();

    expect($columns)->toContain('name', 'tax_id', 'withholding_tax_code', 'withholding_exempt', 'type', 'status')
        ->not->toContain('slug');
});
