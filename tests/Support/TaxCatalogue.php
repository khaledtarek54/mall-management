<?php

namespace Tests\Support;

use App\Models\TaxCode;
use App\Models\TaxRate;
use App\Support\Vat;
use Carbon\CarbonImmutable;

/**
 * Test-side helpers for the tax catalogue.
 *
 * A class, not file-scope functions: Pest parallelises per FILE, a worker only loads the test files
 * it owns, and re-declaring a helper in two of them is a fatal redeclaration.
 *
 * These exist because moving the standard rate is no longer one assignment. It used to be
 * `app(TaxSettings::class)->vat_standard_rate = 20.0` — which is exactly the shape that could not
 * express *when* a rate changed, and is why it is now a dated rung. A test that wants "the standard
 * rate is 20%" is asking for a rung, and this writes one **through the real models**, so the tests
 * exercise the same path the accountant's screen does rather than a fixture only they can produce.
 */
class TaxCatalogue
{
    /**
     * Make `$rate` **the** standard rate: replaces the whole ladder with a single rung.
     *
     * Replacing rather than adding, because "the standard rate is 17.5%" is what a test means, and
     * adding would not achieve it — the migration seeds a 14% rung dated 2017-07-01, so an
     * epoch-dated rung would sit UNDER it and today would still resolve to 14%. (That is the
     * resolver behaving correctly; it is the fixture that has to be unambiguous.)
     *
     * Dated at the epoch by default so it covers any document a test raises, including a back-dated
     * one. Pass `$from` when the point of the test IS the date, then stack later rungs with
     * {@see setRate()}.
     */
    public static function setStandardRate(float $rate, string $from = '2000-01-01'): TaxCode
    {
        $taxCode = self::ensure(Vat::STANDARD_TAX_CODE);
        $taxCode->rates()->delete();

        return self::setRate(Vat::STANDARD_TAX_CODE, $rate, $from);
    }

    /** Add or move ONE rung on any code, creating the code if the catalogue has not been seeded. */
    public static function setRate(string $code, float $rate, string $from = '2000-01-01'): TaxCode
    {
        $taxCode = self::ensure($code);

        TaxRate::updateOrCreate(
            // A Carbon, not the string: `effective_from` is a date cast stored as 'Y-m-d H:i:s', so
            // matching on the bare 'Y-m-d' finds nothing and the upsert becomes an insert that
            // trips the (code, date) unique index.
            ['tax_code_id' => $taxCode->id, 'effective_from' => CarbonImmutable::parse($from)->startOfDay()],
            ['rate' => $rate],
        );

        TaxCode::flushLookupCaches();

        return $taxCode->fresh();
    }

    /**
     * The code, created inactive-then-activated if it is missing.
     *
     * Two steps because `TaxCode` refuses to be switched on while it has no rate and no posting
     * account — the guard that stops a code being offered in a picker and then billing nothing.
     * A test fixture has to satisfy the same rule as the screen, or it would be proving behaviour
     * over a state the application cannot reach.
     */
    private static function ensure(string $code): TaxCode
    {
        $taxCode = TaxCode::firstOrCreate(
            ['code' => $code],
            [
                'name_en' => $code,
                'name_ar' => $code,
                'family' => TaxCode::FAMILY_VAT,
                'direction' => TaxCode::SALES,
                'treatment' => TaxCode::STANDARD,
                'posting_role' => 'vat_payable',
                'invoice_label' => $code,
                'is_active' => false,
            ],
        );

        return $taxCode;
    }

    /** Switch a code on once it has a rung — the accountant's final step. */
    public static function activate(string $code): void
    {
        TaxCode::where('code', $code)->firstOrFail()->update(['is_active' => true]);
        TaxCode::flushLookupCaches();
    }
}
