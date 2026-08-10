<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The escalation collar (الحد الأدنى/الأقصى للزيادة) — a floor and a ceiling on the annual step.
 *
 * Yardi stores a floor and a ceiling alongside the escalation rate, and Atriom stored only the rate
 * (`06-atriom-gap-analysis.md` row 61). The clause it exists for is the standard index-linked one —
 * *"the annual increase shall be the greater of CPI or 3%, capped at 10%"* — and it is the half that
 * decides what a tenant actually pays in the years the index misbehaves.
 *
 * **These bite today, not only when CPI lands.** The collar clamps whatever rate the escalation is
 * about to apply, whatever produced it. On a `fixed_percent` lease that makes the ceiling a real
 * safety rail against a mistyped rate — a `70` entered for `7` is a plausible slip that no other
 * guard catches, and it would step a tenant's rent by seventy percent on the contract anniversary,
 * unattended, at whatever hour the sweep runs. Nullable because most leases have no collar and a
 * default of zero would read as "never increase".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->decimal('escalation_floor_rate', 5, 2)->nullable()->after('escalation_rate');
            $table->decimal('escalation_ceiling_rate', 5, 2)->nullable()->after('escalation_floor_rate');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn(['escalation_floor_rate', 'escalation_ceiling_rate']);
        });
    }
};
