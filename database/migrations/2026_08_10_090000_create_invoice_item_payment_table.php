<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHICH lines a payment settles (story MF-06).
 *
 * **This is detail beneath the invoice, never a second truth.** `Invoice::recomputeTotals()` stays
 * the single source of what is settled — this table only says how an already-counted settlement
 * splits across the lines. `App\Support\InvoiceItemSettlement` derives every per-item figure from
 * `invoices.paid_amount`, so the item outstandings always sum back to the invoice balance no matter
 * what is (or is not) recorded here. Nothing downstream has to learn about a fifth AR channel,
 * because this is not one.
 *
 * **Optional by design.** A payment with no rows here behaves exactly as it does today; the split is
 * then derived by charge-type priority. Rows are for the case the story is about — a remittance
 * advice that says "this is the rent, we are still arguing about the CAM".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_item_payment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->decimal('allocated_amount', 12, 2);
            $table->timestamps();

            // One row per payment per line: re-allocating replaces, never stacks.
            $table->unique(['invoice_item_id', 'payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_item_payment');
    }
};
