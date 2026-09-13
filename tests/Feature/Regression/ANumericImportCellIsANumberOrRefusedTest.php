<?php

/*
|--------------------------------------------------------------------------
| A numeric import cell is a number or the row is refused (SW-261)
|--------------------------------------------------------------------------
| Found by the review of SW-255, the boolean cast's twin. Filament's `ImportColumn::numeric()` casts
| with `floatval(preg_replace('/[^0-9.-]/', '', $cell))` — everything that is not a Latin digit, a
| dot or a hyphen is thrown away and the remainder read as a float — so `TBD`, `-` and `n/a` in a
| rent column imported as **0.00**, `12-500` as 12, and `١٢٬٥٠٠` (Arabic-Indic, which this system
| reads everywhere else) as 0.00 with every digit stripped. The `numeric` rule never saw any of it:
| validation runs on the cast value, and 0.00 is numeric. A migrating operator's file is exactly
| where those tokens live, and each became a silent zero in a money column.
|
| `NumericImportCellIsANumber` reads what a spreadsheet writes around a number — grouping in threes,
| a currency or unit token, `%`, an accounting `(500)`, Arabic-Indic digits and separators — and
| hands anything else back as the raw string for the column's `numeric`/`integer` rule to refuse,
| naming the field. `ImportCellCasts` is the ONE registration carrying it and the boolean seam,
| because `castStateUsing()` is a single slot and a second `configureUsing` would silently replace
| the first — one case here drives both through one row for exactly that reason.
|
| The review of the first cut found three things in the fix, each now a tooth here: any WORD was
| accepted as a unit token and all whitespace was stripped, so `TBD 2027` read as 2027 and
| `Y1 12000` as 112000 — worse than the zero; Excel's Accounting format puts the currency OUTSIDE
| the parentheses, so `EGP (500.00)` read as +500; and `ChargeImporter` writes its rung inside
| `resolveRecord()`, which Filament runs BEFORE validation, so a refused `TBD` row had already
| closed the live rung and opened a 0.00 one under a "failed row" notice.
|
| Every refusal is paired with a control that must still IMPORT, because a seam that refused every
| numeric cell would satisfy the refusals alone.
*/

use App\Filament\Imports\ChargeImporter;
use App\Filament\Imports\LeaseImporter;
use App\Models\Charge;
use App\Models\CustomField;
use App\Models\Lease;
use App\Models\User;
use App\Support\Filament\NumericImportCellIsANumber;
use App\Support\PhpSource;
use Database\Seeders\ChargeCodeSeeder;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->asset = makeAsset(['code' => 'NIM']);
    $this->unit = makeUnit($this->asset, ['code' => 'K-2', 'status' => 'vacant']);
    $this->tenant = makeTenant(['email' => 'numbers@brand.test']);

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
function sw261Import(array $row): Lease
{
    $columnMap = collect(array_keys($row))->mapWithKeys(fn ($k) => [$k => $k])->all();
    (new LeaseImporter(test()->import, $columnMap, []))($row);

    return Lease::where('reference', $row['reference'])->firstOrFail();
}

function sw261Row(array $overrides = []): array
{
    return array_merge([
        'asset_code' => 'NIM',
        'unit_code' => 'K-2',
        'tenant_email' => 'numbers@brand.test',
        'reference' => 'NIM-LEASE-1',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'term_months' => '12',
        'base_rent_monthly' => '10000',
        'service_charge_monthly' => '1500',
        'security_deposit' => '30000',
        'status' => 'active',
    ], $overrides);
}

/** Import a row expecting a refusal on exactly these fields, and no lease left behind. */
function sw261Refused(array $overrides, array $onFields): void
{
    $row = sw261Row(['reference' => 'NIM-LEASE-REFUSED'] + $overrides);

    try {
        sw261Import($row);
        test()->fail('the row was accepted: '.json_encode($overrides, JSON_UNESCAPED_UNICODE));
    } catch (ValidationException $e) {
        foreach ($onFields as $field) {
            expect($e->errors())->toHaveKey($field);
        }
    }

    expect(Lease::where('reference', 'NIM-LEASE-REFUSED')->exists())->toBeFalse();
}

it('reads every notation a spreadsheet writes a number in, and refuses what is not one', function () {
    $read = [
        '12500' => 12500.0,
        '12,500.00' => 12500.0,
        'EGP 12,500' => 12500.0,
        'EGP12,500' => 12500.0,
        '12,500 EGP' => 12500.0,
        '$1,000.00' => 1000.0,
        'LE 12,500' => 12500.0,
        'L.E. 500' => 500.0,
        "\u{62C}.\u{645}.\u{200F} 12,500.00" => 12500.0,   // Excel's Arabic-Egypt currency format, bidi mark and all
        "\u{62C}\u{646}\u{64A}\u{647} 500" => 500.0,        // جنيه
        '12.5%' => 12.5,
        "\u{661}\u{662}\u{66B}\u{665}\u{66A}" => 12.5,      // ١٢٫٥٪
        '(500)' => -500.0,
        '(500.00)' => -500.0,
        'EGP (500.00)' => -500.0,      // Excel's Accounting format: the symbol OUTSIDE the parentheses (review-found, read +500)
        '(500.00) EGP' => -500.0,
        '-500' => -500.0,
        "\u{2212}500" => -500.0,        // the true minus, from a copy out of a PDF
        '+500' => 500.0,
        '.5' => 0.5,
        '1.23E+11' => 123000000000.0,  // what Excel writes for a wide value in General format
        "\u{661}\u{662}\u{66C}\u{665}\u{660}\u{660}\u{66B}\u{665}\u{660}" => 12500.5,   // ١٢٬٥٠٠٫٥٠
        ' 12 ' => 12.0,
        '0' => 0.0,
        '0.00' => 0.0,
    ];
    foreach ($read as $cell => $number) {
        expect(NumericImportCellIsANumber::answer($cell))->toBe($number);
    }

    // Blank is Filament's own rule, kept: null, never zero.
    expect(NumericImportCellIsANumber::answer(''))->toBeNull()
        ->and(NumericImportCellIsANumber::answer('   '))->toBeNull()
        ->and(NumericImportCellIsANumber::answer(null))->toBeNull();

    // The raw cell comes back for the rule to refuse. The first group was a number before this
    // seam; the second group was a number under the seam's FIRST cut — any word taken as a unit
    // and every space stripped, so `TBD 2027` read 2027 and `Y1 12000` read 112000 — and is the
    // reason a unit is a currency ICU can name, `%`, or the pound's abbreviations, and nothing else.
    $refused = [
        'TBD', '-', 'n/a', 'pending', '12-500', '1.2.3', '12,5', '0x1F', '.', "\u{FF11}\u{FF12}",
        'TBD 2027', 'Q1 2027', 'Y1 12000', 'see note 3', 'from 2027', 'per m2 4,800', '12 5', '12 500',
        'No.5', 'XYZ 5', 'TBA 5', 'EGP TBD', 'EGP', '(-5)', '((5))', '(500', '500)', '5%%', "\u{2013}500", '1,', '1.', ',5',
    ];
    foreach ($refused as $cell) {
        expect(NumericImportCellIsANumber::answer($cell))->toBe($cell);
    }

    // …trimmed, because ` 1e5 ` passes Laravel's `numeric` rule and then throws in the decimal cast.
    expect(NumericImportCellIsANumber::answer(' TBD '))->toBe('TBD');
});

it('refuses TBD in the rent column instead of billing a lease at nothing', function () {
    // Before: `TBD` cast to 0.00, `min:0` passed it, and the lease was created billing nothing.
    sw261Refused(['base_rent_monthly' => 'TBD'], ['base_rent_monthly']);
    sw261Refused(['base_rent_monthly' => '-'], ['base_rent_monthly']);
    // The cell a rent column under negotiation actually carries — read as 2027.00 by the first cut.
    sw261Refused(['base_rent_monthly' => 'TBD 2027'], ['base_rent_monthly']);

    // The control: a grouped, currency-prefixed rent still imports, at the right figure.
    $lease = sw261Import(sw261Row(['base_rent_monthly' => 'EGP 12,500.00']));
    expect((float) $lease->base_rent_monthly)->toBe(12500.0);
});

it('refuses a dash in the percentage-rent rate instead of pricing the overage at nothing', function () {
    sw261Refused([
        'has_percentage_rent' => '1',
        'percentage_rent_rate' => '-',
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => '400000',
        'percentage_rent_frequency' => 'monthly',
    ], ['percentage_rent_rate']);

    $lease = sw261Import(sw261Row([
        'has_percentage_rent' => '1',
        'percentage_rent_rate' => '6.5%',
        'percentage_rent_calculation_type' => 'artificial',
        'percentage_rent_threshold' => '400,000',
        'percentage_rent_frequency' => 'monthly',
    ]));
    expect((float) $lease->percentage_rent_rate)->toBe(6.5)
        ->and((float) $lease->percentage_rent_threshold)->toBe(400000.0);
});

it('reads Arabic-Indic digits as the number they are — every digit was stripped before', function () {
    $lease = sw261Import(sw261Row(['base_rent_monthly' => "\u{661}\u{662}\u{665}\u{660}\u{660}"]));   // ١٢٥٠٠

    expect((float) $lease->base_rent_monthly)->toBe(12500.0);
});

it('keeps rounding to the column\'s declared places — an integer column still rounds', function () {
    // Filament's own behaviour, kept: `->integer()` declares 0 places, so 12.7 lands as 13 and the
    // `integer` rule passes it; a seam that dropped the rounding would refuse it as 12.7. Driven on
    // a made column, because `make()` is where the registered cast is installed — so this also
    // proves the seam is wired, not only that the vocabulary rounds.
    expect(ImportColumn::make('useful_life_months')->integer()->castState('12.7'))->toBe(13.0)
        ->and(ImportColumn::make('rate')->numeric(decimalPlaces: 2)->castState('6.456%'))->toBe(6.46)
        ->and(ImportColumn::make('rate')->numeric()->castState('6.456'))->toBe(6.456)
        ->and(ImportColumn::make('rate')->numeric()->castState('TBD'))->toBe('TBD');
});

it('carries the boolean seam and the numeric seam through ONE registration', function () {
    // `castStateUsing()` is a single slot: a second `configureUsing` REPLACES the first. One row,
    // one bad boolean and one bad number, both refused — or one of the two seams is disarmed.
    sw261Refused([
        'requires_sales_reporting' => 'x',
        'base_rent_monthly' => 'TBD',
    ], ['requires_sales_reporting', 'base_rent_monthly']);
});

it('reaches a CUSTOM number field too — the column has to say it is numeric to be covered', function () {
    CustomField::create([
        'model' => 'lease', 'key' => 'fitout_budget', 'type' => 'number',
        'label_en' => 'Fit-out budget', 'label_ar' => "\u{645}\u{64A}\u{632}\u{627}\u{646}\u{64A}\u{629} \u{627}\u{644}\u{62A}\u{62C}\u{647}\u{64A}\u{632}",
    ]);

    // Before: `12,500` failed `is_numeric()` in `castCustomFieldValue()` and was stored as NULL.
    $grouped = sw261Import(sw261Row(['cf_fitout_budget' => '12,500']));
    expect((float) ($grouped->fresh()->custom_fields['fitout_budget'] ?? 0))->toBe(12500.0);

    // …and `TBD` was dropped to null the same way, silently — it is refused now.
    sw261Refused(['cf_fitout_budget' => 'TBD'], ['cf_fitout_budget']);
});

it('leaves the charge schedule UNTOUCHED when a rung is refused — ChargeImporter writes before Filament validates', function () {
    // `ChargeImporter::resolveRecord()` IS the write, and Filament runs `resolveRecord()` before
    // `validateData()`. So until it validated first, `amount = TBD` closed the 5,000 rung in force
    // and opened a 0.00 one — and only THEN reported the row as failed (review-found).
    test()->seed(ChargeCodeSeeder::class);
    // A BLANK service-charge cell: the column is NOT NULL default 0, and until 2026-09-13 the
    // importer sent NULL and the row died on a raw constraint error — found by this fixture.
    $lease = sw261Import(sw261Row(['service_charge_monthly' => '', 'security_deposit' => '']));
    expect((float) $lease->service_charge_monthly)->toBe(0.0)
        // …and the deposit three lines below it had the same shape (the review's second pass).
        ->and((float) $lease->security_deposit)->toBe(0.0);
    $import = fn (array $row) => (new ChargeImporter(test()->import, array_combine(array_keys($row), array_keys($row)), []))($row);

    $import(['lease_reference' => 'NIM-LEASE-1', 'type' => 'service_charge', 'amount' => '5,000', 'effective_from' => '2026-01-01']);
    $before = Charge::where('lease_id', $lease->id)->where('type', 'service_charge')->get(['start_date', 'end_date', 'amount'])->toArray();
    expect($before)->toHaveCount(1)->and((float) $before[0]['amount'])->toBe(5000.0);

    try {
        $import(['lease_reference' => 'NIM-LEASE-1', 'type' => 'service_charge', 'amount' => 'TBD', 'effective_from' => '2026-07-01']);
        test()->fail('a TBD rung was accepted');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('amount');
    }

    // The schedule is exactly what it was: one rung, 5,000, still open-ended.
    expect(Charge::where('lease_id', $lease->id)->where('type', 'service_charge')->get(['start_date', 'end_date', 'amount'])->toArray())
        ->toBe($before);

    // The control: a legitimate second rung still closes the first.
    $import(['lease_reference' => 'NIM-LEASE-1', 'type' => 'service_charge', 'amount' => 'EGP 6,000', 'effective_from' => '2026-07-01']);
    $rows = Charge::where('lease_id', $lease->id)->where('type', 'service_charge')->orderBy('start_date')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->first()->end_date?->toDateString())->toBe('2026-06-30')
        ->and((float) $rows->last()->amount)->toBe(6000.0);
});

it('keeps every numeric import column carrying the rule the seam relies on — a source sweep', function () {
    // The seam hands an unparseable cell back as a STRING so the `numeric`/`integer` rule refuses
    // it; `ImportColumn::rules()` REPLACES, so the seam cannot add the rule itself. A numeric column
    // without it would write the string into a decimal column — text on sqlite, an error on strict
    // MySQL — or, worse, be coerced by the model.
    $files = array_merge(glob(base_path('app/Filament/Imports/*.php')), [base_path('app/Support/Filament/CustomFieldsTable.php')]);
    $numericColumns = 0;

    foreach ($files as $file) {
        // Comments stripped first — a docblock SAYING `->numeric()` is the prose false-positive.
        $chains = preg_split('/ImportColumn::make\(/', PhpSource::withoutComments(file_get_contents($file)));
        array_shift($chains);

        foreach ($chains as $chain) {
            if (! str_contains($chain, '->numeric(') && ! str_contains($chain, '->integer(')) {
                continue;
            }
            $numericColumns++;
            expect((bool) preg_match("/->rules\\((?:(?!ImportColumn::make).)*?'(?:numeric|integer)'/s", $chain))->toBeTrue();
        }
    }

    // The premise: 28 numeric columns on the day this was written — 27 across eight importers and
    // the custom-field column.
    expect($numericColumns)->toBeGreaterThanOrEqual(28);
});
