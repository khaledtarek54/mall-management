<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a tenant must DECLARE their turnover is a separate lease term from whether they PAY
 * percentage rent on it.
 *
 * `has_percentage_rent` was doing both jobs: it decided the charge AND it decided who gets chased
 * for a declaration (`sales:scan-missing-declarations` filters on it). They are different clauses.
 * A mall collects turnover from tenants who owe no percentage rent — for sales per m², for the
 * occupancy-cost ratio that says which tenant is in trouble, and to price a renewal at all — and
 * many leases oblige the disclosure without charging on it. Yardi keeps "Sales Reporting Required"
 * as its own field for exactly this.
 *
 * NULLABLE, and null is the normal state: it means "follow the percentage-rent clause". A plain
 * boolean backfilled from today's flag would freeze the answer — a lease that GAINS percentage rent
 * later would never start being chased, silently. Same reasoning `charges.vat_applicable` records
 * after being bitten by exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table): void {
            $table->boolean('requires_sales_reporting')
                ->nullable()
                ->after('has_percentage_rent');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table): void {
            $table->dropColumn('requires_sales_reporting');
        });
    }
};
