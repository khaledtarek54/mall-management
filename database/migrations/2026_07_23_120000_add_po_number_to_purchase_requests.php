<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A purchase request becomes a Purchase ORDER the moment it is ordered (module 29).
 *
 * Ordering flipped a status and stored a free-text `order_reference` — but the vendor received
 * nothing. Every procurement system produces a numbered, itemized PO you actually send to the
 * supplier; a bare internal status change is not an order to them. `po_number` is that document's
 * own identity, distinct from the internal requisition `reference` (PR-…), stamped at order time.
 *
 * Nullable: only an ORDERED request has a PO number. `order_reference` stays as the *vendor's* own
 * reference for the order (their quote no., our framework-agreement no.) — the two are different
 * things and both are worth keeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('po_number')->nullable()->unique()->after('order_reference');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('po_number');
        });
    }
};
