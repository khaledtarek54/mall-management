<?php

/*
|--------------------------------------------------------------------------
| A lease's sales-reporting duty travels through import and export (SW-255)
|--------------------------------------------------------------------------
| Found by `atriom:doors --check-diff` on SW-254 and pre-existing since the column shipped
| (2026-08-30): `leases.requires_sales_reporting` — and `has_percentage_rent` beside it — had ONE
| door, the lease form. A migrating operator's "must report turnover" column could not be
| imported, and a re-import of an export lost the ruling, silently: the two flags decide who is
| chased for a monthly declaration and who is estimated (`Lease::requiresSalesReporting()`).
|
| Two columns because they are two lease terms — a tenant may owe turnover figures without owing
| percentage rent — and the duty is a THREE-state whose null means "follow the clause". That null
| is the normal state and must survive the round trip: an exported blank re-imports as null, never
| as false (the `charges.vat_applicable` freeze, through the import door). Filament maps import
| columns by LABEL, so the exporter's headers are the importer's guesses.
|
| The review of the first cut found two more, both teeth below: the flag ALONE minted a half-record
| the form refuses (a rate is required whenever the clause is on, a threshold under the artificial
| method), which is chased monthly, estimated on the 17th, prices its overage at 0.00 and cannot be
| saved from its own Edit page — so the clause's four terms travel with it and the row is refused
| without them; and Filament's boolean cast turned any unrecognised token (an Egyptian sheet's
| «لا», Excel's `0.0`, `N/A`) into TRUE, on five sibling columns as well — one seam now
| (`BooleanImportCellIsAnAnswer`) answers yes/no in both languages and refuses the rest.
*/

use App\Filament\Exports\LeaseExporter;
use App\Filament\Imports\LeaseImporter;
use App\Models\CustomField;
use App\Models\Lease;
use App\Models\User;
use App\Support\Filament\BooleanImportCellIsAnAnswer;
use App\Support\PhpSource;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\ValidationException;
use Tests\Support\ExportCells;

beforeEach(function () {
    $this->asset = makeAsset(['code' => 'SRD']);
    $this->unit = makeUnit($this->asset, ['code' => 'B-7', 'status' => 'vacant']);
    $this->tenant = makeTenant(['email' => 'turnover@brand.test']);

    $this->import = Import::create([
        'completed_at' => null,
        'file_name' => 'leases.csv',
        'file_path' => 'leases.csv',
        'importer' => LeaseImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => User::factory()->create()->id,
    ]);
});

/** One CSV line through the same call `ImportCsv` makes, mapping every key it names. */
function sw255Import(array $row): Lease
{
    $columnMap = collect(array_keys($row))->mapWithKeys(fn ($k) => [$k => $k])->all();
    (new LeaseImporter(test()->import, $columnMap, []))($row);

    return Lease::where('reference', $row['reference'])->firstOrFail();
}

/** A row whose percentage-rent clause is complete — the shape the form insists on. */
function sw255ClauseRow(array $overrides = []): array
{
    return sw255Row(array_merge([
        'has_percentage_rent' => '1',
        'percentage_rent_rate' => '6',
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => '400000',
        'percentage_rent_frequency' => 'monthly',
    ], $overrides));
}

function sw255Row(array $overrides = []): array
{
    return array_merge([
        'asset_code' => 'SRD',
        'unit_code' => 'B-7',
        'tenant_email' => 'turnover@brand.test',
        'reference' => 'SRD-LEASE-1',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'term_months' => '12',
        'base_rent_monthly' => '10000',
        'service_charge_monthly' => '1500',
        'security_deposit' => '30000',
        'status' => 'active',
    ], $overrides);
}

it('imports the clause and the duty as two lease terms', function () {
    // The case the column exists for: turnover must be reported, no percentage rent is charged.
    $lease = sw255Import(sw255Row(['has_percentage_rent' => '0', 'requires_sales_reporting' => '1']));

    expect((bool) $lease->has_percentage_rent)->toBeFalse()
        ->and($lease->requires_sales_reporting)->toBeTrue()
        ->and($lease->requiresSalesReporting())->toBeTrue();
});

it('keeps a blank duty as "follow the clause" — null, never false', function () {
    $lease = sw255Import(sw255ClauseRow(['requires_sales_reporting' => '']));

    // A blank cell on a mapped column is null on the row, so the clause decides…
    expect($lease->requires_sales_reporting)->toBeNull()
        ->and($lease->requiresSalesReporting())->toBeTrue();

    // …and an explicit "0" is the other answer: a percentage-rent tenant excused from filing.
    $excused = sw255Import(sw255ClauseRow(['reference' => 'SRD-LEASE-2', 'requires_sales_reporting' => '0']));
    expect($excused->requires_sales_reporting)->toBeFalse()
        ->and($excused->requiresSalesReporting())->toBeFalse();

    // A blank on a RE-IMPORT is the sheet saying "follow the clause" about a lease that carried a
    // ruling — an exported blank must land as null on the way back, which is the one direction a
    // column that ignored blanks (right for the NOT NULL clause beside it) would silently lose.
    $reset = sw255Import(sw255ClauseRow(['reference' => 'SRD-LEASE-2', 'requires_sales_reporting' => '']));
    expect($reset->is($excused))->toBeTrue()
        ->and($reset->requires_sales_reporting)->toBeNull();
});

it('leaves the percentage-rent clause alone on a blank cell — the column is NOT NULL', function () {
    $lease = sw255Import(sw255ClauseRow());
    expect((bool) $lease->has_percentage_rent)->toBeTrue();

    // A re-import with the column mapped and blank must not touch it (nor write NULL into a
    // NOT NULL column, which is the fatal a `data_set` of null would be).
    $again = sw255Import(sw255Row(['has_percentage_rent' => '']));
    expect($again->is($lease))->toBeTrue()
        ->and((bool) $again->has_percentage_rent)->toBeTrue();
});

it('exports both under the labels the importer guesses on, and the round trip restores the ruling', function () {
    $lease = sw255Import(sw255ClauseRow(['requires_sales_reporting' => '0']));
    $untouched = sw255Import(sw255ClauseRow(['reference' => 'SRD-LEASE-3']));

    $row = ExportCells::row(LeaseExporter::class, $lease->refresh());
    // The writer stringifies every cell, so the spreadsheet reads `1` / `0`.
    expect((string) $row['has_percentage_rent'])->toBe('1')
        ->and((string) $row['requires_sales_reporting'])->toBe('0');

    // The duty nobody ruled on exports BLANK — the cell that re-imports as null.
    $rowUntouched = ExportCells::row(LeaseExporter::class, $untouched->refresh());
    expect($rowUntouched['requires_sales_reporting'])->toBeIn([null, '']);

    // Filament maps an import column by its LABEL; the export header IS the column label. Both
    // doors read `admin.fields.*`, in every supported locale.
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        $importLabels = collect(LeaseImporter::getColumns())->mapWithKeys(fn ($c) => [$c->getName() => $c->getLabel()]);
        $exportLabels = collect(LeaseExporter::getColumns())->mapWithKeys(fn ($c) => [$c->getName() => $c->getLabel()]);
        foreach (['has_percentage_rent', 'requires_sales_reporting'] as $column) {
            expect($importLabels[$column])->toBe($exportLabels[$column]);
        }
    }
    app()->setLocale('en');

    // And the round trip: the excused lease's "0" and the untouched lease's blank both survive.
    $lease->update(['requires_sales_reporting' => null]);
    sw255Import(sw255Row(['has_percentage_rent' => (string) $row['has_percentage_rent'], 'requires_sales_reporting' => (string) $row['requires_sales_reporting']]));
    expect($lease->fresh()->requires_sales_reporting)->toBeFalse();
});

it('refuses the flag without its terms — the half-record the form refuses too', function () {
    // Rate missing: chased monthly, estimated, priced at 0.00, and its Edit page refuses every save.
    expect(fn () => sw255Import(sw255ClauseRow(['percentage_rent_rate' => ''])))
        ->toThrow(ValidationException::class, __('admin.validation.import_lease_percentage_rent_needs_rate'));

    // Threshold missing under the artificial method (the default when the method is blank too).
    expect(fn () => sw255Import(sw255ClauseRow(['percentage_rent_threshold' => ''])))
        ->toThrow(ValidationException::class, __('admin.validation.import_lease_percentage_rent_needs_threshold'));
    expect(fn () => sw255Import(sw255ClauseRow(['percentage_rent_calculation_type' => '', 'percentage_rent_threshold' => ''])))
        ->toThrow(ValidationException::class, __('admin.validation.import_lease_percentage_rent_needs_threshold'));

    expect(Lease::where('reference', 'SRD-LEASE-1')->exists())->toBeFalse();

    // Controls: a natural breakpoint needs no threshold; the clause off needs nothing.
    $natural = sw255Import(sw255ClauseRow(['percentage_rent_calculation_type' => 'natural_breakpoint', 'percentage_rent_threshold' => '']));
    expect($natural->percentage_rent_calculation_type)->toBe('natural_breakpoint')
        ->and((float) $natural->percentage_rent_rate)->toBe(6.0);

    $plain = sw255Import(sw255Row(['reference' => 'SRD-LEASE-4', 'has_percentage_rent' => '0']));
    expect((bool) $plain->has_percentage_rent)->toBeFalse();
});

it('lets a partial re-import lean on the terms the lease already carries', function () {
    $lease = sw255Import(sw255ClauseRow());

    // A sheet that restates the flag and nothing else — the record supplies the rate and threshold.
    $again = sw255Import(sw255Row(['has_percentage_rent' => '1']));
    expect($again->is($lease))->toBeTrue()
        ->and((float) $again->percentage_rent_rate)->toBe(6.0)
        ->and((float) $again->percentage_rent_threshold)->toBe(400000.0);
});

it('exports the clause\'s terms and the proration method beside the flags, under the importer\'s labels', function () {
    $lease = sw255Import(sw255ClauseRow(['proration_method' => 'thirty_day']));

    $row = ExportCells::row(LeaseExporter::class, $lease->refresh());
    expect((string) $row['percentage_rent_rate'])->toContain('6')
        ->and($row['percentage_rent_calculation_type'])->toBe('artificial')
        ->and((string) $row['percentage_rent_threshold'])->toContain('400000')
        ->and($row['percentage_rent_frequency'])->toBe('monthly')
        ->and($row['proration_method'])->toBe('thirty_day');

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        $importLabels = collect(LeaseImporter::getColumns())->mapWithKeys(fn ($c) => [$c->getName() => $c->getLabel()]);
        $exportLabels = collect(LeaseExporter::getColumns())->mapWithKeys(fn ($c) => [$c->getName() => $c->getLabel()]);
        foreach (['percentage_rent_rate', 'percentage_rent_calculation_type', 'percentage_rent_threshold', 'percentage_rent_frequency', 'proration_method'] as $column) {
            expect($importLabels[$column])->toBe($exportLabels[$column]);
        }
    }
    app()->setLocale('en');
});

it('reads a boolean cell as an answer in either language, and refuses what is neither', function () {
    // The seam behind every `->boolean()` import column. Filament's own cast made everything it
    // did not recognise TRUE — «لا» included.
    foreach (['1', 'true', 'YES', 'y', 'on', "\u{646}\u{639}\u{645}"] as $yes) {
        expect(BooleanImportCellIsAnAnswer::answer($yes))->toBeTrue();
    }
    foreach (['0', 'false', 'No', 'n', 'off', "\u{644}\u{627}"] as $no) {
        expect(BooleanImportCellIsAnAnswer::answer($no))->toBeFalse();
    }
    expect(BooleanImportCellIsAnAnswer::answer(''))->toBeNull()
        ->and(BooleanImportCellIsAnAnswer::answer(null))->toBeNull();

    // Through the real importer: the Arabic "no" lands as false…
    $arabic = sw255Import(sw255ClauseRow(['requires_sales_reporting' => "\u{644}\u{627}"]));
    expect($arabic->requires_sales_reporting)->toBeFalse();

    // …and a token that is no answer is refused ON the field, naming it, rather than chasing
    // the tenant for a year on the strength of a dash.
    foreach (['N/A', '2', '-', 'x'] as $token) {
        try {
            sw255Import(sw255ClauseRow(['reference' => 'SRD-LEASE-9', 'requires_sales_reporting' => $token]));
            $this->fail("'{$token}' was accepted as a boolean answer");
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('requires_sales_reporting');
        }
    }
    expect(Lease::where('reference', 'SRD-LEASE-9')->exists())->toBeFalse();
});

it('keeps the terms the lease has when a template carries their columns BLANK — and does not crash on a clause-less row', function () {
    // The second review's two highs. A migrating sheet built from the export template carries the
    // term headers on every row: blank on the 80% with no clause, blank on a row that restates the
    // flag and nothing else. The guard reads the record for what the row omits — so the FILL had
    // to agree, or the guard passed on the record's 6% and Filament then wrote NULL over it; and a
    // blank `percentage_rent_frequency` was a raw NOT NULL crash on every clause-less row.
    $lease = sw255Import(sw255ClauseRow(['percentage_rent_calculation_type' => 'natural_breakpoint']));

    $again = sw255Import(sw255Row([
        'has_percentage_rent' => '1',
        'percentage_rent_rate' => '', 'percentage_rent_calculation_type' => '',
        'percentage_rent_threshold' => '', 'percentage_rent_frequency' => '',
    ]));
    expect($again->is($lease))->toBeTrue()
        ->and((float) $again->percentage_rent_rate)->toBe(6.0)
        ->and($again->percentage_rent_calculation_type)->toBe('natural_breakpoint')
        ->and((float) $again->percentage_rent_threshold)->toBe(400000.0)
        ->and($again->percentage_rent_frequency)->toBe('monthly');

    $plain = sw255Import(sw255Row([
        'reference' => 'SRD-LEASE-5', 'has_percentage_rent' => '0',
        'percentage_rent_rate' => '', 'percentage_rent_calculation_type' => '',
        'percentage_rent_threshold' => '', 'percentage_rent_frequency' => '',
    ]));
    expect($plain->percentage_rent_frequency)->toBe('monthly');
});

it('refuses a natural breakpoint on a lease with no base rent — the form\'s third rule, mirrored', function () {
    expect(fn () => sw255Import(sw255ClauseRow(['base_rent_monthly' => '0', 'percentage_rent_calculation_type' => 'natural_breakpoint'])))
        ->toThrow(ValidationException::class, __('admin.validation.natural_breakpoint_needs_base_rent'));

    expect(Lease::where('reference', 'SRD-LEASE-1')->exists())->toBeFalse();
});

it('reads Excel\'s 1.0 and 0.0 as the answers they are', function () {
    expect(BooleanImportCellIsAnAnswer::answer('1.0'))->toBeTrue()
        ->and(BooleanImportCellIsAnAnswer::answer('0.0'))->toBeFalse()
        ->and(BooleanImportCellIsAnAnswer::answer('2'))->toBe('2');
});

it('reaches a CUSTOM boolean field too — the column has to say it is boolean to be covered', function () {
    CustomField::create([
        'model' => 'lease', 'key' => 'has_kiosk', 'type' => 'boolean',
        'label_en' => 'Has kiosk', 'label_ar' => "\u{644}\u{647} \u{643}\u{634}\u{643}",
    ]);

    $no = sw255Import(sw255Row(['cf_has_kiosk' => "\u{644}\u{627}"]));
    expect($no->fresh()->custom_fields['has_kiosk'] ?? null)->toBeFalse();

    $yes = sw255Import(sw255Row(['reference' => 'SRD-LEASE-6', 'cf_has_kiosk' => 'yes']));
    expect($yes->fresh()->custom_fields['has_kiosk'] ?? null)->toBeTrue();

    expect(fn () => sw255Import(sw255Row(['reference' => 'SRD-LEASE-7', 'cf_has_kiosk' => 'N/A'])))
        ->toThrow(ValidationException::class);
});

it('keeps every boolean import column carrying the rule the seam relies on — a source sweep', function () {
    // The seam hands an unrecognised token back as a STRING so the `boolean` rule refuses it;
    // `ImportColumn::rules()` REPLACES, so the seam cannot add the rule itself. A boolean column
    // without it would write the string into a boolean column — text on sqlite, an error on
    // strict MySQL.
    $files = array_merge(glob(base_path('app/Filament/Imports/*.php')), [base_path('app/Support/Filament/CustomFieldsTable.php')]);
    $booleanColumns = 0;

    foreach ($files as $file) {
        // Comments stripped first: a docblock SAYING `->boolean()` is the prose false-positive this
        // codebase has recorded three times over.
        $chains = preg_split('/ImportColumn::make\(/', PhpSource::withoutComments(file_get_contents($file)));
        array_shift($chains);

        foreach ($chains as $chain) {
            if (! str_contains($chain, '->boolean(')) {
                continue;
            }
            $booleanColumns++;
            // The rule INSIDE a `->rules(` call — the custom-field column compares `$field->type`
            // against the same word on the line above, which satisfied a bare string search while
            // the rule itself was gone (mutation-found). Pest matchers take no message argument.
            expect((bool) preg_match("/->rules\\((?:(?!ImportColumn::make).)*?'boolean'/s", $chain))->toBeTrue();
        }
    }

    // The premise: the sweep saw the shipped columns, or it is reporting on nothing.
    expect($booleanColumns)->toBeGreaterThanOrEqual(7);
});
