<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A recurring charge's VAT rate becomes an OVERRIDE. Null means "ask the catalogue".
 *
 * `charges.vat_rate` was written once, from `Vat::rateForType()`, when the row was created — and
 * `MonthlyBillingService` billed that stored number for the life of the lease. So the dated
 * catalogue's headline promise, *a rise entered in advance starts applying by itself on the day*,
 * held for late fees, fines, meter recharges, CAM recoveries and percentage rent — every one-off
 * that resolves at origination — and **did not hold for rent and service charge**, which is the
 * bulk of the money.
 *
 * Proven before changing anything: with a rise to 20% recorded against `VAT_STD` effective
 * 1 September, `Vat::rateForType('service_charge', '2026-09-15')` answered 20.0 while the September
 * invoice billed 14.0 off the frozen row. The operator enters the rise, the screen confirms it, and
 * the output VAT they still owe ETA is quietly under-collected. Amending the lease did not help
 * either: `ChargeScheduleService` carries the old rate onto the new row.
 *
 * Yardi is the standard here — the charge record holds the amount, and the rate comes from a tax
 * table resolved at billing. So: nullable, and null is the normal state. A value means somebody
 * deliberately departed from the catalogue for this charge, which is a real thing (a contract that
 * fixed a rate) and is now visible as what it is instead of hiding among the snapshots.
 *
 * **Existing rows are backfilled to NULL.** They were all written by `Vat::rateForType()` and none
 * departs from the catalogue — there has only ever been one standard rate, and the system is not
 * live. Nulling them changes nothing billed today and makes every future change apply. A genuine
 * override is re-entered on the charge row, where it now reads as a decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable()->default(null)->change();
        });

        // Not `Vat::rateForType()` here: a migration that reads the catalogue would break the day
        // the catalogue changes shape. The set is "every row", and the reasoning is in the docblock.
        DB::table('charges')->update(['vat_rate' => null]);
    }

    public function down(): void
    {
        // 0 rather than the old default: this cannot restore which rate each row held, and a
        // plausible-looking 14 would be a guess presented as history.
        DB::table('charges')->whereNull('vat_rate')->update(['vat_rate' => 0]);

        Schema::table('charges', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable(false)->default(0)->change();
        });
    }
};
