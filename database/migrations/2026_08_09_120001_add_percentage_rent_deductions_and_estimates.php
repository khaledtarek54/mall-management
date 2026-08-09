<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR-03 deductions and PR-04 estimated sales.
 *
 * **Deductions.** Many retail leases credit recoverable charges against percentage rent —
 * *"percentage rent is payable to the extent it exceeds CAM and real-estate tax paid in the same
 * period"*. Yardi marks charge codes deductible and nets them. Atriom had no notion of it, so a
 * lease with that clause could only be billed by hand or billed wrong. Stored as a JSON list of
 * invoice-item types so adding a deductible type needs no migration.
 *
 * **Estimated sales.** A tenant who simply never declares currently pays NO percentage rent — the
 * scan chases them and nothing bills, so silence is a way to avoid the charge entirely. Yardi bills
 * an estimate and retro-bills the true figure when it arrives. `is_estimate` marks such a
 * declaration so it is visibly not the tenant's own number and can be superseded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            // e.g. ["service_charge","cam_recovery"] — item types whose billed amount is credited
            // against the same period's percentage rent. Null/empty = nothing is deductible, which
            // is today's behaviour for every existing lease.
            $table->json('percentage_rent_deductible_types')->nullable()->after('percentage_rent_frequency');
        });

        Schema::table('tenant_sales_declarations', function (Blueprint $table) {
            // The landlord's figure, not the tenant's. Kept distinct so an estimate can never be
            // mistaken for a declaration in an audit, and so the real figure can supersede it.
            $table->boolean('is_estimate')->default(false)->after('declared_sales');
            $table->decimal('deducted_amount', 12, 2)->default(0)->after('calculated_percentage_rent');
        });
    }

    public function down(): void
    {
        Schema::table('leases', fn (Blueprint $t) => $t->dropColumn('percentage_rent_deductible_types'));
        Schema::table('tenant_sales_declarations', fn (Blueprint $t) => $t->dropColumn(['is_estimate', 'deducted_amount']));
    }
};
