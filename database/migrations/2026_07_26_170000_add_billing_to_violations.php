<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Violation fine billing (module 31 completion). `fine_amount` recorded the money assessed but
 * nothing turned it into AR — the operator had no way to actually charge a tenant a fine (the doc's
 * own §3 anticipated this: "if a future slice bills the fine, it must go through the normal AR path").
 * This adds the once-only + traceability anchor, mirroring meter_readings.billed_invoice_id:
 *   - `billed_invoice_id` — the fine invoice this violation produced (null until billed), so a fine is
 *     billed exactly once and its invoice is findable from the violation.
 *   - `billed_at` — when the fine invoice was issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('violations', function (Blueprint $table) {
            $table->foreignId('billed_invoice_id')->nullable()->after('notified_at')
                ->constrained('invoices')->nullOnDelete();
            $table->timestamp('billed_at')->nullable()->after('billed_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('violations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billed_invoice_id');
            $table->dropColumn('billed_at');
        });
    }
};
