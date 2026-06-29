<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A negative CAM true-up is a CREDIT owed to the tenant, not a charge. Billing it
 * as a negative one-off charge could drive a January invoice total negative,
 * which Invoice::recomputeTotals() floors to 0 — silently losing the credit.
 * We now model a negative true-up as a CreditNote; this column links the billed
 * allocation to it (mirrors billed_charge_id) so the books reconciliation can
 * verify a billed allocation is backed by EITHER a charge or a credit note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cam_allocations', function (Blueprint $table) {
            $table->foreignId('billed_credit_note_id')->nullable()->after('billed_charge_id')
                ->constrained('credit_notes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cam_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billed_credit_note_id');
        });
    }
};
