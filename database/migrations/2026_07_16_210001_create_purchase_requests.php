<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement — request-to-purchase (FR-PROC-01..05, FR-WH-02).
 *
 * The FRD, verbatim:
 *   FR-PROC-01 — "create a purchase/procurement request specifying item(s), quantity, and
 *                 justification."
 *   FR-PROC-02 — "route procurement requests through an approval workflow **before order
 *                 placement**."
 *   FR-PROC-04 — "link procurement requests to the Inventory module so that approved purchases
 *                 **update stock upon receipt**."
 *   FR-PROC-05 — "maintain a **status history** for each procurement request (e.g., Requested →
 *                 Approved → Ordered → Received)."
 *   FR-WH-02   — "log stock movements (in/out) with timestamp, user, and **linked work order or
 *                 procurement reference**."
 *
 * FR-WH-02 is why this module matters beyond its own feature: the goods-receipt path today writes
 * a `receipt` movement with a free-text `reference` and NO `source_type`/`source_id`
 * (ListStockMovements::receiveAction). So a receipt credits GRNI (Goods Received Not Invoiced) and
 * nothing can ever find it again to clear it. Measured on the demo books: **166,120 EGP of GRNI
 * credits, zero debits, across 12 lines — 0 of 12 receipts carry a source link.** A receipt raised
 * through a purchase request now carries one, which is both what FR-WH-02 asks for and the
 * precondition for ever clearing GRNI (Dr GRNI / Cr AP against the vendor's bill — see the doc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();

            // Property-owned: a mall's storeroom needs the parts, and its budget pays for them.
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('reference', 40)->unique();

            // FR-PROC-05's ladder. `rejected`/`cancelled` are terminal ends, not steps.
            $table->enum('status', ['requested', 'approved', 'rejected', 'ordered', 'received', 'cancelled'])
                ->default('requested');

            // FR-PROC-01 — "specifying item(s), quantity, and justification". Not nullable: a
            // purchase nobody can justify is the thing an approval workflow exists to catch.
            $table->text('justification');

            // Where the goods land on receipt (FR-PROC-04). Nullable until known; enforced at
            // receipt time when there is anything stockable to receive.
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            // Who we ordered from. Nullable while it is still a request — you approve a need, then
            // choose a supplier.
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();

            // Derived from the lines. Stored because it decides the approval tier (FR-PROC-02) and
            // must not drift from the value someone actually signed off.
            $table->decimal('total_value', 14, 2)->default(0);

            // Frozen at request time, exactly as a spare-part draw does: the record must still say
            // who was SUPPOSED to approve it after someone edits the bands.
            $table->string('required_permission', 100)->nullable();

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_notes')->nullable();

            $table->foreignId('ordered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('ordered_at')->nullable();
            $table->string('order_reference', 100)->nullable(); // the supplier's PO / order no.

            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('received_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['asset_id', 'status'], 'pr_asset_status_index');
            $table->index(['status', 'required_permission'], 'pr_pending_queue_index');
        });

        Schema::create('purchase_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();

            // A catalog item becomes stock on receipt (FR-PROC-04). Nullable because FR-PROC's own
            // preamble says the module handles "spare parts, consumables, **and services**" — and a
            // service is not stock. A line is one or the other; the model enforces that.
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->restrictOnDelete();
            $table->string('description')->nullable(); // for a non-catalog item or a service

            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('line_value', 14, 2)->default(0); // quantity × unit_cost, derived

            // The movement this line produced on receipt — the audit link back to the stock ledger,
            // and what proves a line was not received twice.
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();

            $table->timestamps();

            $table->index('purchase_request_id', 'prl_request_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_lines');
        Schema::dropIfExists('purchase_requests');
    }
};
