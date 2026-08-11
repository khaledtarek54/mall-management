<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taxability moves from PHP into the charge-code catalogue (validation sweep §8 R8).
 *
 * **What was wrong.** Which supplies are outside the scope of VAT lived in `Vat::EXEMPT_TYPES` — a
 * PHP array — while `charge_codes`, the catalogue an accountant maintains without a deploy, carried
 * no taxability column at all. So an accountant could add "key money" and bill it, but could not say
 * whether it was taxable: a new code silently originated at the standard rate, and making it exempt
 * needed a developer. Tax policy in code is exactly what that catalogue exists to avoid.
 *
 * **Yardi is the reference here** and does it the other way round: taxability is a `Tax` flag on the
 * charge code — *"Yes means 'this charge is taxable.' It does not mean 'this charge is a tax.'"* —
 * with the rate configured as data, never compiled in. This column is that flag, widened to three
 * treatments because a VAT jurisdiction needs a distinction a sales-tax flag does not:
 *
 *   - `standard`    — a taxable supply at the standard rate (or `vat_rate_override`, below)
 *   - `exempt`      — outside the scope of VAT (base rent, penalties, the marketing levy)
 *   - `zero_rated`  — taxable at 0%
 *
 * `exempt` and `zero_rated` both bill 0, so they are the same money and a different return: an
 * exempt supply is not a taxable supply, a zero-rated one is. Storing them apart costs one column
 * now; deriving them apart later, from documents that only recorded "0", is impossible.
 *
 * `vat_rate_override` is null for almost every code and means "the standard rate, whatever it is
 * today" — so a rate change still reaches every ordinary code through `TaxSettings`. It exists for
 * the supply that sits on a schedule rate of its own, which would otherwise force a code-side
 * exception the same way the exempt set did.
 *
 * **Origination only, like every other rate in this system.** An issued invoice keeps the rate it
 * was billed at; changing a treatment changes what is billed NEXT and never rewrites history.
 */
return new class extends Migration
{
    /**
     * The out-of-scope supplies as of this migration — the same set `Vat::EXEMPT_TYPES` carried, and
     * the reason this backfill exists: an upgraded database must bill exactly what it billed
     * yesterday. `ChargeCodeVatTreatmentConformanceTest` pins the two together.
     *
     * `parking` is deliberately absent — its answer comes from the setting it is replacing, below.
     */
    private const EXEMPT_ON_UPGRADE = [
        'base_rent',
        'percentage_rent',
        'late_fee',
        'marketing',
        'violation_fine',
        'nsf_fee',
    ];

    public function up(): void
    {
        Schema::table('charge_codes', function (Blueprint $table) {
            // String, not enum: the standing rule here is that a set an accountant may need to grow
            // must not need a migration to grow it. Validated in the form and in App\Support\Vat.
            $table->string('vat_treatment', 16)->default('standard')->after('posting_role');
            // Null = the standard rate from TaxSettings. Only a supply on its own schedule rate
            // fills this in.
            $table->decimal('vat_rate_override', 5, 2)->nullable()->after('vat_treatment');
        });

        DB::table('charge_codes')
            ->whereIn('code', self::EXEMPT_ON_UPGRADE)
            ->update(['vat_treatment' => 'exempt']);

        // Parking's taxability was a setting (`tax.parking_vat_applicable`, shipped 2026-08-10) and
        // is now a row here, so the two cannot disagree. Carry the operator's actual answer across
        // rather than the default — a mall that had already switched parking to taxable must not be
        // quietly switched back. The settings property itself is dropped by the settings migration
        // that follows this one; reading it here still works because migrations run in filename
        // order across both paths.
        $parkingTaxable = json_decode((string) DB::table('settings')
            ->where('group', 'tax')
            ->where('name', 'parking_vat_applicable')
            ->value('payload'), true) === true;

        if (! $parkingTaxable) {
            DB::table('charge_codes')
                ->where('code', 'parking')
                ->update(['vat_treatment' => 'exempt']);
        }
    }

    public function down(): void
    {
        Schema::table('charge_codes', function (Blueprint $table) {
            $table->dropColumn(['vat_treatment', 'vat_rate_override']);
        });
    }
};
