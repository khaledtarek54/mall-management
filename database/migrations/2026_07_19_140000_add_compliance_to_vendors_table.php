<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor compliance / COI (certificate of insurance) — strengthen item #5 from the competitive
 * gap analysis. A mall is legally on the hook for the contractors it lets touch fire systems,
 * lifts and electrical, but Atriom would happily dispatch a blacklisted or uninsured vendor.
 * These columns record the vendor's insurance so a lapsed/absent cert (or a blacklist) can BLOCK
 * dispatch — see Vendor::scopeAssignable() + the MaintenanceWorkOrder saving guard.
 *
 * Compliance lives on the SHARED vendor (one cert covers the vendor across every mall), not per
 * property.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->date('coi_expires_at')->nullable()->after('tax_id'); // insurance validity end
            $table->string('insurer', 200)->nullable()->after('coi_expires_at');
            $table->string('policy_number', 100)->nullable()->after('insurer');
            $table->index('coi_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropIndex(['coi_expires_at']);
            $table->dropColumn(['coi_expires_at', 'insurer', 'policy_number']);
        });
    }
};
