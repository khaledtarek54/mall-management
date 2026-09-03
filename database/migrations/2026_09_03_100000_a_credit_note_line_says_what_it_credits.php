<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A credit-note line records WHICH charge it credits, the way an invoice line records which it
 * raised (SW-216).
 *
 * `invoice_items.type` is the charge code the line was billed under, and every reader that has to
 * ask *"how much of this was service charge"* goes through it. A credit note had no such column —
 * `CreditNote::describeAs()`'s own docblock says so in writing: *"a credit-note line has no `type`
 * column to derive from"* — so a credit could be matched to an invoice but never to a CHARGE.
 *
 * **What that cost.** `SyncCamPoolFromLedgerService` derives what a recovery pool already billed its
 * participants as estimate by summing the service-charge lines of their invoices. It was gross of
 * every credit note, and a PARTIAL credit moves no invoice status, so no status filter could ever
 * see one. That is not exotic: `CreditUnearnedBillingService::isTimeApportioned()` returns true for
 * exactly a monthly, not-in-arrears `service_charge`, so every mid-period move-out and every
 * mid-year resale credits those very lines. The pool then believed it had collected money it had
 * given back, and the annual true-up under-charged by that amount — silently, per tenant.
 *
 * Nullable, and null is the normal state for every row written before this: a line that does not say
 * what it credited is not netted, which leaves those pools exactly as they were rather than guessing.
 *
 * **The backfill is deliberately narrow.** Where the credited invoice has exactly ONE line type,
 * that is what the credit relieved and there is nothing to guess. Where it has several, the row
 * stays null — apportioning a credit across line types is a decision, not a derivation, and a
 * migration is the wrong place to take it.
 *
 * **Run against MySQL 8.0.33 before shipping, not reasoned about.** The suite is SQLite and this
 * migration only ever executes on MySQL, which is precisely the gap that has bitten this codebase
 * before (a `select tbl.*, x, *` that SQLite accepts and MySQL calls a syntax error). Measured on
 * the real driver with a single-type invoice and a mixed one: the first backfilled, the second
 * stayed NULL. `HAVING` with no `GROUP BY` treats the matched set as one group, and every selected
 * expression is an aggregate, so `ONLY_FULL_GROUP_BY` is satisfied; a failed `HAVING` yields no row,
 * which sets NULL — the ambiguous case, handled by arithmetic rather than by a second query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_note_items', function (Blueprint $table) {
            // string(32), never an enum, and governed by the CHARGE CODE catalogue exactly as
            // `invoice_items.type` is — the accountant adds a code with no deploy.
            $table->string('type', 32)->nullable()->after('credit_note_id');
            $table->index('type');
        });

        // One statement, no model events: this is a classification being recovered, not a document
        // being changed, and `CreditNote` refuses edits to a finalised note by design.
        DB::table('credit_note_items')
            ->whereNull('type')
            ->update([
                'type' => DB::raw(
                    '(select max(ii.type) from invoice_items ii'
                    .' join credit_notes cn on cn.id = credit_note_items.credit_note_id'
                    .' where ii.invoice_id = cn.invoice_id'
                    .' having count(distinct ii.type) = 1)'
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table('credit_note_items', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
