<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A credit note knows its property, for the same reason an invoice does.
 *
 * `2026_08_15_110000` moved the property onto `invoices` because four sites derived it by walking
 * `lease -> unit -> asset`, which returns null once `lease_id` is nullable. **`CreditNote` has the
 * identical shape and was missed** — `CreditNoteJournalizer` derived `$note->lease?->unit?->asset_id`,
 * and `CreditNoteService` copied `$note->lease_id = $invoice->lease_id` under a comment that had
 * become false the moment owners could be billed ("An invoice always has a lease — lease_id is NOT
 * NULL"). A credit note against an owner assessment therefore posted with **no property dimension**:
 * balanced, tied out, and absent from that mall's P&L and its owner's statement.
 *
 * Why the gates stayed green through phase 2a: the four sites fixed there were the ones that walked
 * the chain *for invoices*. CreditNote, InvoiceWriteOff and Payment each reach their property by a
 * DIFFERENT chain, and every one of those was still valid for every row that existed at the time.
 *
 * Backfill order matters and is stated: prefer the **invoice's** own `asset_id` (authoritative since
 * 110000, and the only answer for an owner note), and fall back to the lease chain for a STANDALONE
 * note — one raised with no invoice, which `CreditNoteService::applyToInvoice` later binds.
 *
 * Nullable in the schema for the reason 110000 gives — tightening means `->change()`, which on SQLite
 * rebuilds the table and silently drops CHECK constraints. `CreditNote::creating` derives-or-refuses
 * instead.
 *
 * @see docs/plans/08-unit-owners.md §5.2b
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->foreignId('asset_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->index('asset_id');
        });

        // 1. From the invoice the note credits — authoritative, and the only route for an owner note.
        DB::statement(<<<'SQL'
            UPDATE credit_notes
            SET asset_id = (SELECT invoices.asset_id FROM invoices WHERE invoices.id = credit_notes.invoice_id)
            WHERE asset_id IS NULL AND invoice_id IS NOT NULL
        SQL);

        // 2. A standalone note that names a lease but no invoice. Raw SQL so soft-deleted leases and
        //    units are still resolved — a note against a terminated lease is a real credit in a real
        //    mall, and the relations would have scoped exactly those rows out.
        DB::statement(<<<'SQL'
            UPDATE credit_notes
            SET asset_id = (
                SELECT units.asset_id
                FROM leases JOIN units ON units.id = leases.unit_id
                WHERE leases.id = credit_notes.lease_id
            )
            WHERE asset_id IS NULL AND lease_id IS NOT NULL
        SQL);

        // A note with neither an invoice nor a lease is legitimately unscoped until it is applied —
        // `applyToInvoice()` adopts the invoice's property then. So a remaining null here is expected
        // and is NOT an error, which is why this migration does not throw the way 110000 does.
        // `CreditNote::creating` refuses a null only when there is something to derive one from.
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_id');
        });
    }
};
