<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Netting a security deposit against a departing tenant's unpaid invoices (story MF-03, scenario S8).
 *
 * **What was missing.** The move-out final account could REPORT the open AR and the net position,
 * but the settlement disposed of the deposit only — so Yardi's headline behaviour was absent:
 * *"a move-out disposition nets the deposit: 540,000 − 120,000 unpaid − 35,000 damages = 385,000
 * refunded, itemised on one document."* An operator had to settle the invoices separately and hope
 * the two acts agreed.
 *
 * **Why its own document rather than a `DepositTransaction` type.** Applying a deposit is a FOURTH
 * channel into `Invoice::recomputeTotals()`, whose invariant is
 * `paid_amount = captured payments + credit applied + tenant credit applied`. `TenantCreditApplication`
 * is the exact precedent for adding one — its own small model, its own journalizer, **soft-delete as
 * the reversal**, created only by a service. A `DepositTransaction` of type `applied` would inherit
 * NEVER_DELETABLE and have no reversal path at all, which is the wrong shape for something an
 * operator must be able to undo before the tenant has left the building.
 *
 * Posted `Dr Deposits Held / Cr Accounts Receivable`, dated at APPLICATION time — never the original
 * receipt's, so a deposit taken three years ago can settle a current invoice without stranding the
 * entry in a closed period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            // Denormalised so the journalizer and the property-isolation scope read one row.
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('entry_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lease_id', 'entry_date']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_applications');
    }
};
