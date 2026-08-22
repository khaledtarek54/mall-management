<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **A charge says whether it bills ahead of the period or behind it** — EG-30 (M-2).
 *
 * Everything billed in ADVANCE, always: the September run raised September's rent, September's
 * service charge and September's everything-else. Rent in advance is right and is what a lease
 * says. A service charge or a utility recharge is not — those are settled after the period they
 * cover, because until the month has run nobody knows what the common area cost or what the meter
 * read. The gap analysis put it plainly: *"a service charge or utility recharge billed in arrears
 * has no home."*
 *
 * ## Why on the CHARGE and not on the lease
 *
 * Because the case that matters is MIXED. A lease with rent in advance and service charge in
 * arrears is the ordinary Egyptian arrangement, and a per-lease flag cannot express it — it would
 * force the operator to choose which of the two is wrong. `charges.billing_timing` is per row, so
 * one lease's rent bills ahead while its service charge bills behind, on the same invoice.
 *
 * ## One invoice, not two
 *
 * The arrears lines ride on the same monthly invoice and name the month they cover — *"Service
 * charge - August 2026 (in arrears)"*. The alternative, a second invoice per lease per month whose
 * period is the previous month, was rejected on evidence: `alreadyBilledForMonth()` has silently
 * suppressed a lease's base rent FIVE times when a second invoice was dated into a month the
 * recurring run also bills (percentage rent, CAM, utility recharge, violation fine, NSF fee, late
 * fee), and its own comment now says *"anything that raises its own invoice dated into a billed
 * month belongs here, and belongs here in the same commit that starts raising it."* Every one of
 * those five was a ONE-OFF. A recurring second invoice would be the same trap firing monthly, for
 * every arrears lease.
 *
 * The cost of that choice, stated: the invoice's `period_start`/`period_end` no longer bounds every
 * line. It already does not — a late fee, a utility recharge and a violation fine all ride on
 * invoices covering a different window — so the line's own description is what a tenant reads, and
 * that is where the covered month is now written.
 *
 * ## Null is the normal state
 *
 * Nullable, null = advance, registered in `ValueSets` so the column cannot hold a third thing. Every
 * existing charge bills exactly as it did and no figure moves on deploy; the operator opts one
 * charge row at a time into arrears. Deliberately NOT defaulted per charge type in the seeder —
 * whether this operator settles service charges monthly in arrears or quarterly in advance is
 * their practice to state, not ours to assume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            // string, not an enum: `NoDatabaseEnumsConformanceTest` keeps the count at zero, and
            // the set lives in `App\Support\ValueSets` where widening it is a code change rather
            // than an ALTER on a hot table.
            $table->string('billing_timing', 16)
                ->nullable()
                ->after('frequency');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('billing_timing');
        });
    }
};
