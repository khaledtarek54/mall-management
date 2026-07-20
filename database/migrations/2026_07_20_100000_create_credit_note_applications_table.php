<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records each application of a credit note against a specific invoice, so an application can be
 * UN-APPLIED precisely (return the note's balance, re-open the invoice's AR) — which the aggregate
 * `credit_notes.applied_amount` / `invoices.credit_applied_amount` columns can't express on their own.
 * This is the link that lets (a) cancelling an invoice un-apply exactly the credit it consumed
 * (instead of issuing a second offsetting note that double-counted the sales-return), and (b) a
 * guided "reverse application" action. It is NOT a GL source: the credit note's own journal entry
 * (posted at issue) already carries the ledger effect; an application only moves subledger balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamp('applied_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes(); // un-applying soft-deletes the row (audit trail of what was reversed)

            $table->index(['credit_note_id', 'deleted_at']);
            $table->index(['invoice_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_applications');
    }
};
