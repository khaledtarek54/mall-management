<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a vendor bill to the purchase it pays for — the seam that finally clears GRNI.
 *
 * THE BUG THIS FIXES (proven, not theorised). Buying 500 EGP of stock ONCE produced:
 *
 *   Inventory  +500   (asset)      ← from the receipt
 *   Expense    +500   (P&L)        ← from the vendor's bill: THE SAME MONEY, AGAIN
 *   GRNI       −500   (liability)  ← from the receipt, never cleared
 *   AP         −500   (liability)  ← from the bill
 *
 * The cost is recognised twice — once as an asset and once as an expense — and the liability
 * is recorded twice. The P&L and the balance sheet are each overstated by the full value of
 * every stock purchase whose supplier bill is entered. Measured on the demo books before this:
 * **166,120 EGP of GRNI credits against zero debits.**
 *
 * Standard perpetual inventory says a receipt and its bill are two halves of one purchase:
 *
 *   Receipt              Dr Inventory / Cr GRNI      "we have the goods, not yet the invoice"
 *   Bill for those goods Dr GRNI      / Cr AP        "here is the invoice" — GRNI nets to zero
 *   Consumption          Dr Expense   / Cr Inventory  the cost hits the P&L when it is USED
 *
 * The middle line was impossible: nothing connected a bill to the receipt it paid for. That is
 * what this column is. `InventoryMovementJournalizer`'s docblock has called it "a future
 * enhancement" since the module shipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_bills', function (Blueprint $table) {
            // Nullable, and it stays that way: most bills are for services and have no purchase
            // behind them. A bill WITH one is the goods half of a purchase and clears GRNI
            // instead of re-charging the expense.
            //
            // nullOnDelete, not cascade: deleting a purchase request must never delete a
            // supplier's invoice. The bill is the money; the request is only the paperwork that
            // led to it.
            $table->foreignId('purchase_request_id')->nullable()->after('reference')
                ->constrained('purchase_requests')->nullOnDelete();

            $table->index('purchase_request_id', 'vb_purchase_request_index');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_request_id');
        });
    }
};
