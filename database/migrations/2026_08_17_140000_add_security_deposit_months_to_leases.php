<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **A deposit negotiated as "three months' rent" has to stay three months' rent.**
 *
 * `security_deposit` is a flat figure, and rent escalates. On a 7% clause a deposit agreed at 3×
 * covers 2.62 months by year three and 2.29 by year five — the landlord's security against a
 * defaulting tenant erodes by nearly a quarter over a term, silently, and precisely as the tenant
 * becomes more likely to default. Yardi tracks the deposit requirement against rent for this reason;
 * the gap analysis had it as a 🟡 "note only".
 *
 * The column records the MULTIPLE that was negotiated, and it is **nullable on purpose**: a deposit
 * agreed as a flat sum unrelated to rent is a real deal too, and inferring a multiple by dividing
 * the deposit by the rent would invent a term nobody agreed. Null = flat, and nothing moves.
 *
 * Existing leases stay null, so this migration changes no figure anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->decimal('security_deposit_months', 5, 2)
                ->nullable()
                ->after('security_deposit');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('security_deposit_months');
        });
    }
};
