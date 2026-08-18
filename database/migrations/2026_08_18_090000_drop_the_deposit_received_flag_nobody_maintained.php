<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `leases.security_deposit_received` was a SECOND TRUTH about the same money.
 *
 * It was a form toggle, defaulted false at creation, and **nothing ever synced it** from the deposit
 * register. So a lease with 240,000 recorded against it still read "not received", and an operator
 * could tick the box on a lease where nothing had ever arrived. Two answers to "has the deposit been
 * paid?", one of them a guess somebody typed months ago.
 *
 * The register is the answer, and it is now reachable from the model: `Lease::depositHeld()` and
 * `Lease::depositShortfall()`, derived from recorded receipts less refunds, forfeits and
 * applications. A boolean cannot express a PARTLY collected deposit at all, which is the ordinary
 * case — 150,000 held against a contractual 180,000 is neither true nor false.
 *
 * No data migration: the column carried no information that the transactions do not already hold,
 * and where the two disagreed the transactions were right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('security_deposit_received');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->boolean('security_deposit_received')->default(false)->after('security_deposit');
        });
    }
};
