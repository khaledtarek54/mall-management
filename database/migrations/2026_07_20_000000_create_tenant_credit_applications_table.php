<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant CREDIT APPLICATION: moving an amount of a tenant's on-account credit (the unallocated
 * remainder of their received payments, sitting in Unearned Revenue) onto one of their open invoices.
 *
 * It is its OWN accounting document, posted Dr Unearned Revenue / Cr Accounts Receivable dated at
 * APPLICATION time (entry_date = now, always an open period) — NOT by re-deriving the original
 * (immutable, possibly closed-period) payment entry. That decoupling is the whole point: applying an
 * old overpayment to a current invoice must post into an open period, or AR would drop while the GL
 * refused to move (the critical bug a first attempt hit). Reversal = soft-delete (the sweep voids the
 * entry, the invoice AR re-opens, the credit returns to available).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_credit_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            // The property the credit is applied within (= the invoice's asset). The GL Dr Unearned /
            // Cr AR both post to this asset, so per-property books stay correct. Nullable to mirror the
            // other money sources whose asset can be the consolidated (null) dimension.
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('entry_date'); // the GL entry_date — application day, an OPEN period
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes(); // reversal = soft-delete → journalizer skips → sweep voids the entry

            $table->index(['tenant_id', 'deleted_at']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_credit_applications');
    }
};
