<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales declarations are now file-first: the tenant uploads their sales report
 * (mobile app / portal) and the operator reads the figure off it, so
 * `declared_sales` is no longer supplied at submission time — it is entered by
 * staff when they review the attachment, then locked to bill the percentage
 * rent. Make the column nullable so a freshly-submitted, not-yet-reviewed
 * declaration can persist without a figure. (NOT-NULL invariant: an optional
 * form field must never push null into a NOT-NULL column — hence this change.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_sales_declarations', function (Blueprint $table) {
            $table->decimal('declared_sales', 14, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Backfill any pending (null) figures to 0 so the column can go back to
        // NOT NULL without failing on rows the operator never processed.
        Schema::table('tenant_sales_declarations', function (Blueprint $table) {
            $table->decimal('declared_sales', 14, 2)->default(0)->nullable(false)->change();
        });
    }
};
