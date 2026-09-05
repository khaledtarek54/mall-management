<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a credit note relieves the DEPOSIT obligation, frozen on the row (SW-238).
 *
 * The write-off side of this was SW-210, and this is its twin through the credit door:
 * `CreditNoteJournalizer` debits `sales_returns` — contra-REVENUE — for every line, but a
 * `security_deposit` line credited `deposits_held`, a LIABILITY, at issue. So crediting a deposit
 * invoice booked a revenue reversal for revenue never recognised and left the obligation standing:
 * a fully credited 100,000 deposit left the GL saying 100,000 held where the truth is 0, and
 * `deposits_tie_out` red with no write-off anywhere near it.
 *
 * **Frozen, and backfilled to 0.00 — PROSPECTIVE, for exactly SW-210's reason.** The lines carry a
 * `type` since SW-216, but its backfill typed HISTORICAL lines (where the credited invoice had one
 * line type), so keying the journalizer on the type would restate already-posted entries on the
 * next sweep — into periods that may since have closed, which is the unclearable `gl_in_sync`
 * drift of SW-236. Every existing note keeps the entry it already has; only notes written from now
 * on split. The model maintains the figure from its own items while the note is unposted evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->decimal('deposit_amount', 14, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn('deposit_amount');
        });
    }
};
