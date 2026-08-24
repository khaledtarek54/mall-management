<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this invoice was last SENT to its tenant (UX5-09).
 *
 * The daily conversation is *"I never received it"*, and nothing in the system could answer it:
 * `InvoiceIssuedNotification` was dispatched from `MonthlyBillingService` alone, so an invoice raised
 * by any other path — a violation fine, a CAM recovery, an NSF fee, a one-off an operator typed —
 * notified nobody at all, and there was no record either way. The operator's only recourse was to
 * download the PDF and attach it to their own email.
 *
 * Null therefore means exactly what it says: **this tenant has never been sent this invoice.** That
 * is true of every historical row, including ones the monthly run did email, because the fact was
 * never recorded — so the column is deliberately NOT backfilled. Inventing a send date from
 * `issue_date` would put a confident timestamp behind a claim nobody can check, on the one question
 * this column exists to settle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('tenant_notified_at')
                ->nullable()
                ->after('dunning_level')
                ->comment('When the invoice was last emailed to the tenant (null = never sent)');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('tenant_notified_at');
        });
    }
};
