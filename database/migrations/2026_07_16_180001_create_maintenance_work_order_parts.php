<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spare parts on a work order (FR-CM-09/10/11 + FR-INV-04) — module 26.
 *
 * FR-CM-09: a part is sourced from **internal inventory OR bought from an outside
 * supplier**. FR-INV-04 wants that distinction recorded. Today it isn't recorded at all —
 * it is *implied by row existence*: a StockMovement exists ⇒ internal, and an externally
 * bought part is simply **absent** from the system rather than marked external. So "what
 * did we buy outside this month?" is unanswerable.
 *
 * FR-CM-10/11: an internal draw needs approval, and **which** approver depends on the
 * part's value. That means a draw cannot decrement stock the moment someone asks for it —
 * it has to wait for a decision.
 *
 * Which is why this is its own table rather than a status on `stock_movements`: that table
 * is the stock LEDGER, and every on-hand sum in the system trusts that a row means the
 * stock actually moved. A pending row there would silently understate on-hand everywhere
 * until someone remembered to filter it. A part request is a *request*; the movement is
 * created only once it's approved, so the ledger keeps meaning exactly one thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_work_order_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_work_order_id')->constrained('maintenance_work_orders')->cascadeOnDelete();

            // FR-CM-09 / FR-INV-04 — the distinction the system could not previously express.
            $table->enum('source', ['internal', 'external']);

            // Internal: what was drawn, and from where. Null for an external purchase.
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();

            // External: what was bought and from whom, in free text — an outside part has no
            // SKU in our catalog, which is the whole point of it being external.
            $table->string('description')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('reference')->nullable(); // the supplier's invoice / receipt no.

            $table->decimal('quantity', 14, 3);
            // Frozen at request time. Re-reading the catalog later would restate the value a
            // manager actually approved when the standard cost moves.
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('value', 14, 2)->default(0); // quantity × unit_cost

            // pending  → an internal draw awaiting approval (FR-CM-10)
            // approved → the stock movement has been created; the parts are issued
            // rejected → refused, with a reason
            // recorded → an external purchase; nothing to approve, nothing to decrement
            $table->enum('status', ['pending', 'approved', 'rejected', 'recorded'])->default('pending');

            // The tier the ladder demanded, frozen at request time (FR-CM-11) — so the
            // record still explains who was *supposed* to sign it off after the bands change.
            $table->string('required_permission', 100)->nullable();

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_notes')->nullable();

            // The movement this draw produced, once approved. nullOnDelete so voiding a
            // movement leaves the request visible rather than deleting the history of it.
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();

            $table->timestamps();

            $table->index(['maintenance_work_order_id', 'status'], 'mwop_order_status_index');
            $table->index(['status', 'required_permission'], 'mwop_pending_queue_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_work_order_parts');
    }
};
