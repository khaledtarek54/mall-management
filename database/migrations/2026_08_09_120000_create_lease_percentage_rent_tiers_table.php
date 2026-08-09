<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lease_percentage_rent_tiers` — a breakpoint LADDER, not a single rate.
 *
 * Atriom could express exactly one breakpoint and one rate, which cannot represent an anchor or
 * large-format deal at all: those are written as bands. Yardi's own example is 0–500K at 0%,
 * 500K–900K at 5%, above 900K at 6% (Commercial brochure). The first 0% band IS the breakpoint,
 * which is why a ladder subsumes the single-threshold model rather than sitting beside it.
 *
 * Each band applies only to the sales that fall **within** it — a tenant at 1,000,000 pays 5% on
 * the 400,000 between the second band's edges and 6% on the 100,000 above, not 6% on everything.
 * Getting that wrong overcharges every large tenant, so `LeasePercentageRentTier::overageFor()`
 * is the one place the arithmetic lives.
 *
 * Bands are stored per lease because they are negotiated per deal. `from_amount`/`to_amount` are
 * SALES levels on the lease's own basis: monthly figures for a monthly lease, yearly for an annual
 * one — the same convention `percentage_rent_threshold` already follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_percentage_rent_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();

            $table->decimal('from_amount', 14, 2)->default(0);
            // Null = unbounded: the top band always runs to infinity, or a ladder would silently
            // stop charging above its own ceiling.
            $table->decimal('to_amount', 14, 2)->nullable();
            $table->decimal('rate', 5, 2)->default(0);

            $table->timestamps();

            $table->index(['lease_id', 'from_amount']);
        });

        // 'tiered' as a third calculation type, so a ladder is an explicit choice rather than
        // something inferred from the presence of rows (which would make deleting the last tier
        // silently change the billing basis).
        Schema::table('leases', function (Blueprint $table) {
            $table->enum('percentage_rent_calculation_type', ['natural_breakpoint', 'artificial', 'tiered'])
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->enum('percentage_rent_calculation_type', ['natural_breakpoint', 'artificial'])
                ->nullable()
                ->change();
        });

        Schema::dropIfExists('lease_percentage_rent_tiers');
    }
};
