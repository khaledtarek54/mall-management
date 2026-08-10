<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The lease's other two dates — possession and rent commencement (Yardi gap-analysis row 46).
 *
 * Yardi carries six dates on a lease; Atriom carried two (`commencement_date`, `expiry_date`) and
 * expressed the rent-free build-out period as `fit_out_months`, a whole-month integer counted from
 * the commencement month. Two of the missing four are the ones that actually decide money and
 * disputes:
 *
 * - **`possession_date`** — when the tenant took the keys and fit-out began. This is the date a
 *   handover dispute turns on ("we couldn't start until you gave us the unit"), and it is routinely
 *   *before* the term commences. Recorded and shown; it deliberately does not move any billing,
 *   because nothing bills before commencement anyway.
 * - **`rent_commencement_date`** — when rent starts. This REPLACES `fit_out_months`, which is
 *   dropped below.
 *
 * **Why replace rather than sit beside it.** A month count and a date are two ways of saying the
 * same thing, and once both exist nobody can tell which one billing believes — the exact ambiguity
 * that made `units.floor` / `units.floor_level` worth deleting. The date is the better of the two:
 * a real lease says "rent commences 1 April", not "three months of fit-out", and a count cannot
 * express a mid-month start or a renegotiated hand-back at all.
 *
 * **The backfill is exact, not approximate.** `Lease::firstBillableMonth()` computed
 * `commencement.startOfMonth()->addMonths(fit_out_months)`, so that is what is written here — in
 * PHP rather than SQL, because the month arithmetic differs between MySQL and SQLite and the whole
 * point is that no lease changes what it bills. A lease with `fit_out_months = 0` gets a NULL
 * rent-commencement (it bills from commencement), which keeps the null-means-no-grace reading.
 *
 * `fit_out_scope` is untouched and still decides WHAT the grace abates (gross = nothing bills,
 * rent-only = rent is free while the service charge still bills).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->date('possession_date')->nullable()->after('commencement_date');
            $table->date('rent_commencement_date')->nullable()->after('possession_date');
        });

        DB::table('leases')
            ->select('id', 'commencement_date', 'fit_out_months')
            ->whereNotNull('commencement_date')
            ->where('fit_out_months', '>', 0)
            ->orderBy('id')
            ->each(function ($lease) {
                DB::table('leases')->where('id', $lease->id)->update([
                    'rent_commencement_date' => CarbonImmutable::parse($lease->commencement_date)
                        ->startOfMonth()
                        ->addMonths((int) $lease->fit_out_months)
                        ->toDateString(),
                ]);
            });

        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('fit_out_months');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->unsignedTinyInteger('fit_out_months')->default(0)->after('marketing_levy_rate');
        });

        DB::table('leases')
            ->select('id', 'commencement_date', 'rent_commencement_date')
            ->whereNotNull('rent_commencement_date')
            ->whereNotNull('commencement_date')
            ->orderBy('id')
            ->each(function ($lease) {
                $months = CarbonImmutable::parse($lease->commencement_date)->startOfMonth()
                    ->diffInMonths(CarbonImmutable::parse($lease->rent_commencement_date)->startOfMonth());

                DB::table('leases')->where('id', $lease->id)->update([
                    'fit_out_months' => max(0, (int) $months),
                ]);
            });

        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn(['possession_date', 'rent_commencement_date']);
        });
    }
};
