<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Low-stock alerts (FR-INV-03).
 *
 * The FRD: "The system shall support minimum-stock thresholds and low-stock alerts (**recommended
 * addition — confirm with client if desired**)." It is the FRD's own suggestion rather than a
 * confirmed need, so the whole thing sits behind the `inventory` module flag and a setting, and it
 * only ever *notifies* — an alert cannot move stock or money. Flagged in BUSINESS-RULES.
 *
 * **Why a table.** Every other scheduled scan here stamps its "already alerted" flag on the row it
 * alerted about (`invoices.owner_overdue_notified_at`, `tenant_requests.sla_breach_notified_at`).
 * A low-stock alert has no such row: its subject is a **pair** — this item, in this mall — and
 * `inventory_items` is a SHARED global catalog with no property dimension at all. Stamping the item
 * would silence the alert for every other mall.
 *
 * So the pair gets a row. It doubles as the thing that makes re-alerting sane: one row per
 * (item, property), reused — `notified_at` set when it fires, `resolved_at` when the stock comes
 * back. A restock followed by a second dip alerts again, which is right; a scan running hourly
 * against a shortage nobody has fixed does not, which is also right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('low_stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            // What it looked like when it fired — so the notification and the audit trail agree
            // even after the numbers move.
            $table->decimal('on_hand', 14, 3)->default(0);
            $table->decimal('reorder_level', 14, 3)->default(0);

            $table->dateTime('notified_at')->nullable();
            $table->dateTime('resolved_at')->nullable();

            $table->timestamps();

            // One row per pair, reused across the alert's whole life. This unique index is also
            // the backstop against two concurrent scans both inserting (the command locks and
            // re-checks inside the transaction, but the DB is what makes it true).
            $table->unique(['inventory_item_id', 'asset_id'], 'lsa_item_asset_unique');
            $table->index(['asset_id', 'resolved_at'], 'lsa_open_by_asset_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('low_stock_alerts');
    }
};
