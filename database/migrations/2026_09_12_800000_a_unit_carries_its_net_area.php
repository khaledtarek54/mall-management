<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A unit carries its NET area beside its gross one (client meeting 2026-09-02, point 20 —
 * *"gross w net le msa7a le unit"*).
 *
 * `units.area_sqm` is the GROSS area — the chargeable figure: rent per m², the recovery share and the
 * occupancy figures all read it, and it stays the only measure any money rule reads. `net_area_sqm`
 * is the part inside the demise — what the tenant actually occupies once shared corridors, columns
 * and service space are taken out — and it is INFORMATIONAL: printed beside the gross on the
 * register, the lease agreement and the rent roll, never billed on. The market's shape: a space
 * carries a rentable and a usable area with the load factor between them, and charges run on the
 * rentable one (docs/benchmarks/yardi/01 §2.3).
 *
 * Nullable, because blank means NOT MEASURED — a load factor against a missing figure is unknown,
 * not 100%, the same reading `Asset::leasableEfficiencyPct()` gives the property's pair. Not dated
 * like `unit_areas`: nothing apportions on it, so a change has no past period to protect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->decimal('net_area_sqm', 10, 2)->nullable()->after('area_sqm');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('net_area_sqm');
        });
    }
};
