<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Late-fee terms become a lease-level override (story MF-08).
 *
 * **What was wrong.** `LateFeeService` applied one global rate, minimum and grace period to every
 * invoice in the portfolio. Real leases do not agree on any of the three: an anchor tenant
 * negotiates 30 days' grace, a kiosk gets 5, and the rate is a bargaining chip. The system billed
 * what the config said rather than what each contract said.
 *
 * All three are NULLABLE, and null means "use the portfolio default" — so every existing lease
 * behaves exactly as it does today and an operator only fills in the ones that were negotiated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->decimal('late_fee_percent', 6, 2)->nullable()->after('payment_terms_days');
            $table->unsignedSmallInteger('late_fee_grace_days')->nullable()->after('late_fee_percent');
            $table->decimal('late_fee_minimum', 12, 2)->nullable()->after('late_fee_grace_days');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn(['late_fee_percent', 'late_fee_grace_days', 'late_fee_minimum']);
        });
    }
};
