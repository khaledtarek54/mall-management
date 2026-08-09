<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The monthly straight-line rent adjustment (story RA-02).
 *
 * One row per lease per month: what the lease was BILLED, what it should RECOGNISE, and the
 * difference posted between Deferred Rent and Rental Income. Stored rather than derived because it
 * is a posted accounting entry — re-deriving it later would silently restate a period whose terms
 * have since been amended, which is exactly what "forward-only" forbids.
 *
 * `unique(lease_id, period)` is the idempotency guarantee: the monthly sweep can run twice, or be
 * re-run after a failure, without double-posting a month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('straight_line_rent_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            // First day of the month being recognised.
            $table->date('period');
            $table->decimal('billed_amount', 14, 2);
            $table->decimal('straight_line_amount', 14, 2);
            // straight_line − billed. Positive = recognise more than billed.
            $table->decimal('adjustment_amount', 14, 2);
            $table->date('entry_date');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['lease_id', 'period']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('straight_line_rent_adjustments');
    }
};
