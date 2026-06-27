<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency stamp for the "payment received" tenant notification. The manual
 * Create/Edit pages allocate the payment AFTER the model save, so the saved()
 * hook fired (or skipped) the notification against stale allocations. The
 * notification now fires once via Payment::notifyReceiptOnce(), guarded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('receipt_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('receipt_notified_at');
        });
    }
};
