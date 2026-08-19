<?php

use App\Models\ChargeCode;
use App\Models\TaxCode;
use App\Models\TaxRate;
use App\Support\PostingRoles;
use App\Support\Vat;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\TaxCodeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The tax catalogue's own integrity gate.
 *
 * `tax_codes` is where the rate a tenant is charged now lives, so the failure modes are the ones
 * that end in money: a code offered in a picker that resolves to no rate at all, a tax collected
 * into no account, a floor in PHP that disagrees with the rows an accountant maintains.
 *
 * The catalogue is **the operator's own sheet** (2026-07-19), so it also gates the two ways a
 * client's data stops being the client's: rows invented beyond it, and rows quietly dropped from it.
 *
 * Most of it ships switched OFF — stamp and schedule tax carry the operator's rates but have no GL
 * account for their family yet, and an active code that collected into nowhere would be worse than
 * an absent one. That decision is only safe if incompleteness is inert, which is what most of the
 * rest of this file asserts.
 */
beforeEach(function () {
    $this->seed(TaxCodeSeeder::class);
});

it('ships every rate-less code switched off', function () {
    // The load-bearing assertion under the whole "seed the vocabulary, not the numbers" decision.
    // An ACTIVE code with an empty ladder would be offered on every invoice line and resolve to no
    // rate — the exact silent under-collection that seeding a guess was meant to avoid.
    $liveButRateless = TaxCode::query()
        ->where('is_active', true)
        ->where('treatment', TaxCode::STANDARD)
        ->get()
        ->filter(fn (TaxCode $c) => $c->rates()->doesntExist())
        ->pluck('code')
        ->all();

    expect($liveButRateless)->toBe([],
        "These tax codes are switched on but have no rate, so they can be picked and will bill nothing:\n"
        .implode(', ', $liveButRateless));
});

it('never activates a taxable code that would collect into no account', function () {
    $roleless = TaxCode::query()
        ->where('is_active', true)
        ->where('treatment', TaxCode::STANDARD)
        ->whereNull('posting_role')
        ->pluck('code')
        ->all();

    expect($roleless)->toBe([],
        'These tax codes are switched on but name no posting account: '.implode(', ', $roleless));
});

it('refuses to switch on a code that cannot bill, and says which half is missing', function () {
    // The guard behind the two assertions above, exercised rather than assumed. Removing it is what
    // makes this test fail — a state check alone would stay green over a deleted guard as long as
    // the seeder happened to be well-behaved.
    //
    // Both fixtures are BUILT here rather than borrowed from the catalogue. This test used to reach
    // for `SCHD_8` as its rate-but-no-account case, which was true right up until schedule tax was
    // commissioned (2026-08-19) and then silently stopped testing the guard — a fixture that depends
    // on a catalogue row staying incomplete is a fixture with an expiry date on it.
    $roleless = TaxCode::create([
        'code' => 'SCHD_97', 'name_en' => 'Schedule 97%', 'name_ar' => 'ضريبة الجدول ٩٧٪',
        'family' => TaxCode::FAMILY_SCHEDULE, 'direction' => TaxCode::SALES,
        'treatment' => TaxCode::STANDARD, 'posting_role' => null,
        'invoice_label' => 'SCHD 97%', 'is_active' => false,
    ]);
    TaxRate::create([
        'tax_code_id' => $roleless->id, 'rate' => 97.0, 'effective_from' => '2017-07-01',
    ]);

    // It HAS a rate. What it lacks is the account, and that alone must still refuse.
    expect($roleless->rates()->exists())->toBeTrue();
    expect(fn () => $roleless->update(['is_active' => true]))->toThrow(DomainException::class);

    // And the other half of the guard, on a code with an account but no rate.
    $rateless = TaxCode::create([
        'code' => 'SCHD_99', 'name_en' => 'Schedule 99%', 'name_ar' => 'ضريبة الجدول ٩٩٪',
        'family' => TaxCode::FAMILY_SCHEDULE, 'direction' => TaxCode::SALES,
        'treatment' => TaxCode::STANDARD, 'posting_role' => 'vat_payable',
        'invoice_label' => 'SCHD 99%', 'is_active' => false,
    ]);
    expect(fn () => $rateless->update(['is_active' => true]))->toThrow(DomainException::class);

    // The control — with both, it activates. Without this the two refusals above would pass just as
    // happily if activation were refused unconditionally.
    TaxCode::whereKey($roleless->id)->firstOrFail()->update(['posting_role' => 'schedule_tax_payable']);
    TaxCode::whereKey($roleless->id)->firstOrFail()->update(['is_active' => true]);

    expect(TaxCode::whereKey($roleless->id)->value('is_active'))->toBeTrue();
});

it('agrees with the floor on what the standard rate is', function () {
    // `Vat::DEFAULT_STANDARD_RATE` is what an UNSEEDED database bills. If it drifts from the seeded
    // rung, the same supply is taxed differently depending on whether the seeder has run — the
    // catalogue-versus-floor drift this whole design exists to remove, rebuilt one layer down.
    expect(TaxCode::rateOn(Vat::STANDARD_TAX_CODE))->toBe(Vat::DEFAULT_STANDARD_RATE)
        ->and(TaxCodeSeeder::VAT_STANDARD_RATE)->toBe(Vat::DEFAULT_STANDARD_RATE);
});

it('names only posting roles the chart actually knows', function () {
    // A role nobody registered posts nowhere. The journalizer would fall back or fail at the moment
    // money is collected, which is the worst time to discover a typo.
    $unknown = TaxCode::query()
        ->whereNotNull('posting_role')
        ->pluck('posting_role', 'code')
        ->reject(fn (string $role) => PostingRoles::group($role) !== null)
        ->all();

    expect($unknown)->toBe([],
        'These tax codes name a posting role that is not registered: '.json_encode($unknown));
});

it('classifies every code with a family, a direction and a treatment the model knows', function () {
    foreach (TaxCode::all() as $code) {
        expect(in_array($code->treatment, TaxCode::TREATMENTS, true))
            ->toBeTrue("{$code->code} carries an unknown treatment '{$code->treatment}'");
        expect(in_array($code->family, TaxCode::FAMILIES, true))
            ->toBeTrue("{$code->code} carries an unknown family '{$code->family}'");
        expect(in_array($code->direction, TaxCode::DIRECTIONS, true))
            ->toBeTrue("{$code->code} carries an unknown direction '{$code->direction}'");
    }
});

it('carries the operator\'s whole sheet, in both directions', function () {
    // The catalogue implements the operator's own `account.tax` sheet (2026-07-19), whose standing
    // instruction is "do not invent rows beyond this sheet". This asserts the other half of that:
    // do not QUIETLY DROP rows from it either. Both are how a catalogue stops being the client's.
    $expected = [
        TaxCode::FAMILY_VAT => ['VAT_14', 'VAT_0', 'VAT_EXEMPT'],
        TaxCode::FAMILY_STAMP => ['STAMP_20'],
        TaxCode::FAMILY_SCHEDULE => ['SCHD_0_5', 'SCHD_1', 'SCHD_5', 'SCHD_8', 'SCHD_10', 'SCHD_15', 'SCHD_30'],
        TaxCode::FAMILY_WITHHOLDING => ['WH_0_5', 'WH_1', 'WH_3', 'WH_5'],
    ];

    foreach ($expected as $family => $codes) {
        foreach ($codes as $code) {
            foreach (['' => TaxCode::SALES, '_P' => TaxCode::PURCHASES] as $suffix => $direction) {
                $row = TaxCode::where('code', $code.$suffix)->first();

                expect($row)->not->toBeNull("{$code}{$suffix} is on the operator's sheet but not in the catalogue");
                expect($row->family)->toBe($family)
                    ->and($row->direction)->toBe($direction);
            }
        }
    }
});

it('never offers a withholding code as a tax on a supply', function () {
    // Withholding is not a tax on a supply: it is deducted from what is paid to a supplier and
    // remitted for them, and its rates are stored NEGATIVE. Offering it on an invoice line would
    // let an operator bill a tenant under "Withholding -1%" — which `Vat::rateForType()` then
    // clamps to 0, so it would look like it had worked.
    //
    // Kept out by FAMILY rather than by being switched off, because the codes are legitimately
    // active: the vendor-payment path asks for them by family (roadmap TX-05).
    foreach ([TaxCode::SALES, TaxCode::PURCHASES] as $direction) {
        $offered = array_keys(TaxCode::options($direction));
        $withholding = TaxCode::ofFamily(TaxCode::FAMILY_WITHHOLDING)->pluck('code')->all();

        expect(array_intersect($offered, $withholding))->toBe([],
            "A withholding code is offered as a {$direction} supply tax: ".implode(', ', array_intersect($offered, $withholding)));
    }

    // The control — the pickers are not simply empty.
    expect(TaxCode::options(TaxCode::SALES))->toHaveKey('VAT_14')
        ->and(TaxCode::options(TaxCode::PURCHASES))->toHaveKey('VAT_14_P');
});

it('activates a tax the same way in both directions', function () {
    // Every rate on the operator's sheet exists as a sales row and a purchases row. If the two
    // disagree about being usable, one side of the books can classify a supply the other cannot.
    //
    // This is not hypothetical: activation once turned on "has a posting role", which made it an
    // accident of which LAYER created the row — the sales-side exempt and zero-rated codes come
    // from the 120100 migration (which writes them active) and their purchases twins from the
    // seeder (which did not). `VAT_EXEMPT` was offered on an invoice while `VAT_EXEMPT_P` was
    // missing from every purchase form.
    $mismatched = [];

    foreach (TaxCode::ofDirection(TaxCode::SALES)->get() as $sales) {
        $twin = TaxCode::where('code', $sales->code.'_P')->first();

        if ($twin !== null && $twin->is_active !== $sales->is_active) {
            $mismatched[] = "{$sales->code} (".($sales->is_active ? 'on' : 'off').") vs {$twin->code} (".($twin->is_active ? 'on' : 'off').')';
        }
    }

    expect($mismatched)->toBe([], "These taxes are usable on one side of the books only:\n".implode("\n", $mismatched));
});

it('makes every code that collects nothing usable straight away', function () {
    // Exempt and zero-rated need neither a rate nor an account — there is nothing to collect and
    // nothing to post — so they are the codes that must NOT wait on GL wiring. Base rent is billed
    // under one, so shipping them off would leave the commonest supply in the catalogue unpickable.
    $off = TaxCode::query()
        ->where('treatment', '!=', TaxCode::STANDARD)
        ->where('is_active', false)
        ->pluck('code')
        ->all();

    expect($off)->toBe([], 'These collect nothing yet ship switched off: '.implode(', ', $off));
});

it('stores withholding as a deduction, not an addition', function () {
    // The operator's sheet writes these negative — "WH -1%" — because the tax comes OFF what is
    // paid. Storing the sign is what keeps that true when the vendor-payment path reads them.
    foreach (TaxCode::ofFamily(TaxCode::FAMILY_WITHHOLDING)->get() as $code) {
        expect($code->currentRate())->toBeLessThan(0.0, "{$code->code} is withholding but its rate is not negative");
    }

    // The control: nothing else is negative — a VAT line that came out negative would be a credit
    // hiding inside a tax figure.
    foreach (TaxCode::query()->where('family', '!=', TaxCode::FAMILY_WITHHOLDING)->get() as $code) {
        expect($code->currentRate())->toBeGreaterThanOrEqual(0.0, "{$code->code} carries a negative rate");
    }
});

it('carries the statute for every code it cannot state a rate for', function () {
    // A blank rate is only actionable if the row says which law to open. Without it the accountant
    // is left a blank box and a guess, which is the state this catalogue replaced.
    $unsourced = TaxCode::query()
        ->get()
        ->filter(fn (TaxCode $c) => $c->rates()->doesntExist() && blank($c->statutory_reference))
        ->pluck('code')
        ->all();

    expect($unsourced)->toBe([],
        'These codes have neither a rate nor a statutory reference: '.implode(', ', $unsourced));
});

it('cannot hold two rates for one code on one day', function () {
    // Overlapping windows are the data error that makes a legacy charge schedule bill NOTHING.
    // The shape here makes them unrepresentable — a rung runs until the next begins — and the
    // unique index is what makes "the latest rung on or before this date" a single answer.
    $vat = TaxCode::where('code', Vat::STANDARD_TAX_CODE)->firstOrFail();
    $existing = $vat->rates()->firstOrFail();

    expect(fn () => DB::table('tax_rates')->insert([
        'tax_code_id' => $vat->id,
        'rate' => 99,
        'effective_from' => $existing->effective_from->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('holds a tax code that charge codes are billed under', function () {
    $this->seed(ChargeCodeSeeder::class);

    // Every charge code names a tax that exists. A charge code pointing at a missing tax silently
    // falls to the floor, which is right as a safety net and wrong as a shipped state.
    $dangling = ChargeCode::query()
        ->whereNotNull('tax_code')
        ->pluck('tax_code', 'code')
        ->reject(fn (string $tax) => TaxCode::knows($tax))
        ->all();

    expect($dangling)->toBe([],
        'These charge codes name a tax code that is not in the catalogue: '.json_encode($dangling));

    // …and the deletion policy's guard relation actually resolves, so "refused while in use" is a
    // working guard rather than a typo that blocks nothing.
    expect(TaxCode::where('code', 'VAT_14')->firstOrFail()->chargeCodes()->exists())->toBeTrue();
});
