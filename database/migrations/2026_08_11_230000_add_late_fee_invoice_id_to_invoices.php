<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The late-fee invoice raised because THIS invoice went unpaid.
 *
 * Late fees used to be appended as a line item to the overdue invoice itself. That restated an
 * issued document, and — because `InvoiceJournalizer` dates its entry from `issue_date` — booked
 * April's penalty as January revenue, from an 04:00 cron, into a month already reported to the
 * owner. The fee is now its own invoice dated when it was incurred, which is what CAM true-ups,
 * percentage-rent overages and violation fines already do.
 *
 * The link lives on the OVERDUE invoice pointing at the fee, mirroring `Violation::billed_invoice_id`
 * — the source record carries the invoice id, not the other way round. It is what makes the charge
 * idempotent under a concurrent sweep, and a `cancelled` fee invoice frees the source to be charged
 * again (a cancelled invoice's GL entry is voided, so nothing is double-counted).
 *
 * `nullOnDelete` rather than cascade: money records are never deletable, but if a fee invoice ever
 * did vanish the source invoice must survive it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('late_fee_invoice_id')
                ->nullable()
                ->after('is_opening_balance')
                ->constrained('invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('late_fee_invoice_id');
        });
    }
};
