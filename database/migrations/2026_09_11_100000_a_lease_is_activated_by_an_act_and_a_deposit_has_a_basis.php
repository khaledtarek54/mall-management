<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client meeting 2026-09-02, points 1 · 2 · 3.
 *
 * `security_deposit_basis` — HOW the deposit was agreed: `months` (a multiple of the monthly rent,
 * what `security_deposit_months` has expressed since EG-35), `percent_of_annual_rent` (the Egyptian
 * / GCC clause convention — "10% of the annual rent"), or `fixed` (a sum unrelated to rent, what a
 * null `security_deposit_months` has always meant). Backfilled from the months column so no lease
 * changes meaning: a row with a multiple is `months`, a row without one is `fixed`.
 *
 * `reserved_until` — the day a reservation lapses. A draft or pending lease holds its shop off the
 * market (`Unit::recomputeStatus()` reads it as `reserved`); Yardi's unit hold carries an expiry
 * for exactly this reason. Null = no window (the shipped default is 0 days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->string('security_deposit_basis', 32)->default('months')->after('security_deposit_months');
            $table->decimal('security_deposit_percent', 5, 2)->nullable()->after('security_deposit_basis');
            $table->date('reserved_until')->nullable()->after('status');
        });

        DB::table('leases')->whereNull('security_deposit_months')->update(['security_deposit_basis' => 'fixed']);
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn(['security_deposit_basis', 'security_deposit_percent', 'reserved_until']);
        });
    }
};
