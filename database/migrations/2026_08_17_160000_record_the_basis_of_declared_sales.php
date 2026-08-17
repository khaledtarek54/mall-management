<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **A declaration becomes a sales certificate: gross, what came off it, and why.**
 *
 * `declared_sales` was one number with no stated basis — nothing said whether it was gross or net of
 * anything. Percentage rent is charged on it, and if tenants report the VAT-inclusive figure their
 * POS prints by default the charge is wrong: because the breakpoint is subtracted first, a 14% error
 * in sales becomes a ~70% error in the overage on a typical clause.
 *
 * These columns change no arithmetic. `declared_sales` remains exactly what every calculation
 * already reads — the NET figure — and is now DERIVED from the two new ones when they are present.
 * A declaration recorded the old way (gross null) is untouched and keeps meaning what it meant.
 *
 *   - `tenant_sales_declarations.gross_sales` — the figure on the tenant's own certificate
 *   - `tenant_sales_declarations.sales_exclusions` — itemised deductions, `{type: amount}`
 *   - `leases.percentage_rent_sales_exclusions` — which exclusions THIS lease's clause grants, so an
 *     operator cannot credit a deduction the contract never gave
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_sales_declarations', function (Blueprint $table) {
            $table->decimal('gross_sales', 14, 2)->nullable()->after('declared_sales');
            $table->json('sales_exclusions')->nullable()->after('gross_sales');
        });

        Schema::table('leases', function (Blueprint $table) {
            $table->json('percentage_rent_sales_exclusions')
                ->nullable()
                ->after('percentage_rent_deductible_types');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_sales_declarations', function (Blueprint $table) {
            $table->dropColumn(['gross_sales', 'sales_exclusions']);
        });

        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('percentage_rent_sales_exclusions');
        });
    }
};
