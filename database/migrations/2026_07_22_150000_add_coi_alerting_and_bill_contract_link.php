<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 12 completion — two gaps the compliance gate left open.
 *
 * 1. A COI expired SILENTLY. `Vendor::assignable()` drops a lapsed vendor from every picker, so
 *    the operator's contractor just vanished from the dropdown with no warning and no chance to
 *    chase the renewal. These two columns stamp what was already alerted, keyed on the COI date
 *    itself — so renewing the cert (a NEW coi_expires_at) re-arms the alert automatically, and a
 *    re-run never re-nags. Nullable strings, not enums (project convention).
 *
 * 2. A bill was never tied to the CONTRACT it was incurred under, so `vendor_contracts.value`
 *    was decorative: nothing compared committed vs actual. Nullable — ad-hoc bills with no
 *    contract stay legal, and every existing bill keeps working untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('coi_alert_stage')->nullable()->after('policy_number');
            $table->date('coi_alert_for')->nullable()->after('coi_alert_stage');
        });

        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->foreignId('vendor_contract_id')->nullable()->after('vendor_id')
                ->constrained('vendor_contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_contract_id');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['coi_alert_stage', 'coi_alert_for']);
        });
    }
};
