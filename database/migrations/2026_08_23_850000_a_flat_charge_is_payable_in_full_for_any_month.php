<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **A charge says whether it prorates at all** — the per-charge prorate flag.
 *
 * EG-29 made the proration METHOD a lease term: how a part-month is priced, on four methods, chosen
 * per property with a lease override. What it did not make settable is the prior question — whether
 * this particular charge prorates in the first place. Every monthly row prorated together, so a
 * mid-month move-in cut a flat signage licence, a fixed parking fee and a fixed management fee by
 * the same fraction it cut the rent.
 *
 * Those charges are not time-priced. A signage licence buys the right to hang a sign in a month;
 * hanging it from the 15th does not make it half a sign. Yardi's lease charge row carries the flag
 * for exactly this reason — *"charge code · amount · from date · to date · frequency · basis ·
 * prorate flag"* (docs/benchmarks/yardi/01-yardi-lease-administration.md §3.2) — and it sits on the
 * CHARGE rather than the lease because the case that matters is mixed: rent prorates, the licence
 * beside it does not, on one lease and one invoice. The same reasoning that put `billing_timing`
 * here rather than on the lease.
 *
 * ## Null is the normal state
 *
 * Nullable; null and true both mean "prorate, by the lease's method", which is what every charge
 * did before this column existed. Only an explicit `false` changes anything, so no figure moves on
 * deploy and the operator opts one row at a time — the same shape as `charges.billing_timing` and
 * `charges.vat_applicable`, and tested as `=== false` rather than falsy for the reason EG-01
 * records: a falsy test reads null as a decision nobody made.
 *
 * ## It is not a fifth proration method
 *
 * `prorate = false` bills on {@see App\Support\ProrationMethod::WHOLE_MONTH} — the existing rule,
 * not a new one. That matters beyond tidiness: `MonthlyBillingService::monthsCovered()` is the ONE
 * definition of "how much of a period does this agreement run", and the termination credit
 * (`CreditUnearnedBillingService`) reads the same rule so a credit cannot disagree with the invoice
 * it credits. A separate "bill it whole" branch in the billing service would have been a second
 * definition, and the credit would have clawed back half of a month the charge says is fully
 * earned — the tenant refunded for a licence they held.
 *
 * A month the agreement does not reach at all still bills nothing: whether a part-month is worth a
 * whole month is a different question from whether the lease ran in that month, and WHOLE_MONTH
 * answers only the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->boolean('prorate')
                ->nullable()
                ->after('billing_timing');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('prorate');
        });
    }
};
