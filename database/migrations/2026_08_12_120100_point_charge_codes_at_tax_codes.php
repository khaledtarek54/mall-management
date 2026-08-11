<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A charge code names the TAX it is billed under, instead of restating the tax's own properties.
 *
 * `charge_codes.vat_treatment` + `vat_rate_override` (both shipped 2026-08-11) answered "is this
 * supply taxable, and at what rate" per charge code. That was the right question in almost the right
 * place — Yardi puts taxability on the charge code too — but it stored the *answer* rather than a
 * reference to the thing that holds it, and so it could not express the one property a rate must
 * have: **a date**. Twelve charge codes each carrying their own copy of "14" is twelve rows to edit
 * on the day the rate moves, with no way to say *when* it moves and no way to keep billing the old
 * rate on a document dated before it.
 *
 * After this migration a charge code points at a `tax_codes` row and the rate is resolved from that
 * code's dated ladder, per document date. One place states the rate; one place states its history.
 *
 * ## The backfill must preserve the accountant's rulings exactly
 *
 * A charge code the accountant had exempted must still bill 0 afterwards, and one left standard
 * must still bill the standard rate. So the mapping is by treatment:
 *
 *   - `exempt`      → `VAT_EXEMPT`
 *   - `zero_rated`  → `VAT_0`
 *   - `standard`    → `VAT_14`
 *   - `standard` **with a `vat_rate_override`** → its own tax code at that rate (`VAT_8`, `VAT_8_5`),
 *     created on demand. In a tax-code world a supply on its own schedule rate *is* a distinct tax,
 *     which is how Odoo and SAP model it; folding it back into the standard code would silently
 *     re-rate that supply.
 *
 * ## Why this migration seeds, rather than leaving it to `TaxCodeSeeder`
 *
 * Seeders do not run on upgrade. If the codes this backfill points at only existed in the seeder,
 * an upgraded production database would come up with every `tax_code` null, every accountant ruling
 * lost, and exempt supplies quietly billing the standard rate through the unclassified fallback.
 * So the three sales-side VAT codes are created here if absent. `TaxCodeSeeder` remains the
 * canonical catalogue — it is idempotent, and it adds the rest of the operator's sheet (the
 * purchases direction, stamp, schedule and withholding). `TaxCatalogueConformanceTest` asserts the
 * two agree on the codes both define, so this baseline is a safety net and never a second opinion.
 *
 * The operator's own standard rate is carried across the same way `parking_vat_applicable` was:
 * read out of `settings` here, before the settings migration that follows drops it.
 */
return new class extends Migration
{
    /** The day the current VAT regime began; every rung this migration writes is dated from it. */
    private const EPOCH = '2017-07-01';

    private const VAT_LAW = 'VAT Law 67/2016 — ضريبة القيمة المضافة';

    /** Treatment on the old column => the tax code that reproduces it. */
    private const TREATMENT_MAP = [
        'exempt' => 'VAT_EXEMPT',
        'zero_rated' => 'VAT_0',
        'standard' => 'VAT_14',
    ];

    public function up(): void
    {
        Schema::table('charge_codes', function (Blueprint $table) {
            // By code, not by id: `invoice_items.type` already references a charge code by string,
            // and an id here would make the seeded catalogue's auto-increment part of the contract
            // between environments. Nullable — an unclassified code falls to the floor in
            // App\Support\Vat, exactly as `posting_role` falls to misc_income.
            $table->string('tax_code', 32)->nullable()->after('posting_role');
            $table->index('tax_code');
        });

        $standardRate = $this->configuredStandardRate();

        $this->ensureTaxCode('VAT_14', 'VAT 14%', 'ضريبة القيمة المضافة ١٤٪', 'standard', 'vat_payable', 'VAT 14%', 10, $standardRate);
        $this->ensureTaxCode('VAT_0', 'Zero Rated 0%', 'خاضعة بنسبة صفر', 'zero_rated', null, 'Zero Rated 0%', 20, 0.0);
        $this->ensureTaxCode('VAT_EXEMPT', 'Exempt', 'معفاة', 'exempt', null, 'Exempt', 30, 0.0);

        foreach (DB::table('charge_codes')->get(['id', 'code', 'vat_treatment', 'vat_rate_override']) as $charge) {
            $treatment = $charge->vat_treatment ?: 'standard';
            $override = $charge->vat_rate_override === null ? null : (float) $charge->vat_rate_override;

            // An override only means anything on a taxable supply — `Vat::rateForType()` has always
            // returned 0 for a non-standard treatment whatever the override said, and
            // ChargeCodeVatTreatmentConformanceTest pins that. Honouring it here would start taxing
            // a supply the accountant had exempted.
            $taxCode = ($treatment === 'standard' && $override !== null && $override != $standardRate)
                ? $this->ensureRateCode($override)
                : (self::TREATMENT_MAP[$treatment] ?? 'VAT_14');

            DB::table('charge_codes')->where('id', $charge->id)->update(['tax_code' => $taxCode]);
        }

        Schema::table('charge_codes', function (Blueprint $table) {
            $table->dropColumn(['vat_treatment', 'vat_rate_override']);
        });
    }

    public function down(): void
    {
        Schema::table('charge_codes', function (Blueprint $table) {
            $table->string('vat_treatment', 16)->default('standard')->after('posting_role');
            $table->decimal('vat_rate_override', 5, 2)->nullable()->after('vat_treatment');
        });

        $treatments = array_flip(self::TREATMENT_MAP);

        foreach (DB::table('charge_codes')->get(['id', 'tax_code']) as $charge) {
            DB::table('charge_codes')->where('id', $charge->id)->update([
                'vat_treatment' => $treatments[$charge->tax_code] ?? 'standard',
            ]);
        }

        Schema::table('charge_codes', function (Blueprint $table) {
            $table->dropIndex(['tax_code']);
            $table->dropColumn('tax_code');
        });
    }

    /**
     * The standard rate this installation is actually billing at.
     *
     * `TaxSettings::vat_standard_rate` is the live figure until the settings migration alongside
     * this one deletes it. An operator who had moved it off 14 must keep their rate — carrying the
     * default instead would change what every taxable supply bills on the day of an upgrade.
     */
    private function configuredStandardRate(): float
    {
        $configured = json_decode((string) DB::table('settings')
            ->where('group', 'tax')
            ->where('name', 'vat_standard_rate')
            ->value('payload'), true);

        return is_numeric($configured) ? (float) $configured : 14.0;
    }

    private function ensureTaxCode(
        string $code,
        string $en,
        string $ar,
        string $treatment,
        ?string $role,
        string $label,
        int $sort,
        ?float $rate = null,
    ): void {
        $existing = DB::table('tax_codes')->where('code', $code)->value('id');

        if ($existing === null) {
            $existing = DB::table('tax_codes')->insertGetId([
                'code' => $code,
                'name_en' => $en,
                'name_ar' => $ar,
                'family' => 'vat',
                'direction' => 'sales',
                'treatment' => $treatment,
                'posting_role' => $role,
                'invoice_label' => $label,
                'statutory_reference' => self::VAT_LAW,
                'is_active' => true,
                'sort_order' => $sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($rate !== null && ! DB::table('tax_rates')->where('tax_code_id', $existing)->exists()) {
            DB::table('tax_rates')->insert([
                'tax_code_id' => $existing,
                'rate' => $rate,
                'effective_from' => self::EPOCH,
                'note' => self::VAT_LAW,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * A tax code for a supply on its own schedule rate — `VAT_8`, `VAT_8_5`.
     *
     * Dated at the epoch of this system's records rather than "today": the override was already in
     * force on every document that carries it, so a ladder starting today would leave those
     * documents' dates below the first rung.
     */
    private function ensureRateCode(float $rate): string
    {
        $suffix = str_replace('.', '_', rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.'));
        $code = "VAT_{$suffix}";
        $label = 'VAT '.rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.').'%';

        $this->ensureTaxCode($code, $label, "ضريبة القيمة المضافة {$rate}٪", 'standard', 'vat_payable', $label, 40, $rate);

        return $code;
    }
};
