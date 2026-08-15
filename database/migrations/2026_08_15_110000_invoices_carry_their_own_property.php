<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice now knows which property it belongs to, instead of inferring it.
 *
 * Until now FOUR places answered "which mall is this invoice's?" by walking `lease → unit → asset`:
 * the property-isolation registry, `InvoiceJournalizer` (the GL's asset dimension), the document
 * number prefix, and the marketing-levy accrual. That worked only because `lease_id` was NOT NULL —
 * every one of them was *entitled* to treat the lease as the route to the property.
 *
 * Phase 2 of plan 08 makes `lease_id` nullable so a unit OWNER, who has no lease, can be invoiced
 * for the service charge. The day that lands, all four silently answer null: the invoice drops out
 * of every property-scoped query, its journal entry posts with no property dimension, it is numbered
 * `INV-AW-…` whatever mall it is in, and the levy stops finding its budget. None of them fails
 * loudly. So the property moves onto the invoice's own row FIRST, on its own, with every existing
 * row backfilled and the tie-out re-checked.
 *
 * This is the established house pattern for exactly this reason — `disbursements.asset_id` and
 * `owner_statements.asset_id` are both denormalized, the first annotated *"journalizer reads its own
 * row"*. It is also what Yardi does: AR belongs to a property by construction, never by inference
 * through whichever document happened to raise it.
 *
 * ## Why the column is NULLABLE here and NOT NULL in spirit
 *
 * The two precedents above are `->constrained()` with no `nullable()`. This one cannot be, and the
 * reason is worth stating so nobody "corrects" it later: the column must be added, backfilled and
 * only then tightened, and tightening means `->change()` on `invoices`. On SQLite — which is what the
 * whole test suite runs on — a `->change()` REBUILDS the table from its introspected schema and
 * **silently drops every CHECK constraint on it**. `invoices.status` and `invoices.eta_status` are
 * exactly such constraints (`2026_08_12_270000_free_every_remaining_db_enum_column`), so tightening
 * this column would quietly un-guard two others, in the test database only, where nothing would ever
 * notice.
 *
 * The non-nullness is therefore enforced where this project already enforces what the database
 * cannot — at the model. `Invoice::creating` derives it and REFUSES to create an invoice without
 * one; `Invoice::updating` refuses to clear it. Same trade, and the same reasoning, as
 * `App\Support\ValueSets` standing in for the DB enums this codebase removed.
 *
 * @see docs/plans/08-unit-owners.md §5.2b
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // restrictOnDelete, matching the peers: a property that still carries receivables is not
            // a property anybody may remove.
            $table->foreignId('asset_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->index('asset_id');
        });

        // Backfill from the chain being retired. A correlated UPDATE rather than a chunked loop so
        // it is one statement on both drivers, and raw SQL so soft-deleted leases and units are
        // included — a cancelled lease's invoices are still real receivables in a real mall, and the
        // Eloquent relations would have scoped them out and left exactly those rows null.
        DB::statement(<<<'SQL'
            UPDATE invoices
            SET asset_id = (
                SELECT units.asset_id
                FROM leases
                JOIN units ON units.id = leases.unit_id
                WHERE leases.id = invoices.lease_id
            )
            WHERE asset_id IS NULL
        SQL);

        // Fail loudly rather than deploy a half-answered column. A row that cannot resolve is a data
        // problem an operator must see — silently leaving it null would reintroduce, on exactly the
        // rows that are already odd, the invisibility this migration exists to remove.
        $unresolved = DB::table('invoices')->whereNull('asset_id')->count();

        if ($unresolved > 0) {
            throw new RuntimeException(
                "Cannot backfill invoices.asset_id: {$unresolved} invoice(s) resolve to no property. "
                .'Each has a lease whose unit is missing or whose unit has no asset_id — fix those rows, then re-run.'
            );
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_id');
        });
    }
};
