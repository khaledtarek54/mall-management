<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `invoice_write_offs` — accepting that a receivable will not be paid, as its own dated document.
 *
 * A write-off is an accounting EVENT, not a flag on the invoice: it has its own date (which may
 * differ from the invoice's), its own reason, its own author, and its own journal entry
 * (Dr Bad Debt Expense / Cr Accounts Receivable). Modelling it as a column would have left the GL
 * with nothing to post and no date to post it on — the same reason tenant-credit application is a
 * document rather than a column.
 *
 * Reversal is a soft-delete (a recovered debt), which the ledger sweep then voids — matching
 * `TenantCreditApplication`. The row is never edited: a wrong write-off is reversed and re-made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_write_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            // Denormalised so the GL entry and every property-scoped report can find its mall
            // without walking invoice → lease → unit on every row.
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();

            $table->decimal('amount', 12, 2);
            // The DECISION date, operator-typed — which is why it needs a posting-date guard.
            $table->date('entry_date');
            $table->string('reason', 40);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'entry_date']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_write_offs');
    }
};
