<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `description_key` + `description_data` on `invoice_items` and `credit_note_items` (UX-30).
 *
 * The third instalment of a rule this codebase has already applied twice — `JournalNarrative` for
 * the ledger and `LeaseEventNarrative` for the lease timeline: **a row stores DATA, never PROSE.**
 *
 * What is left frozen is the line text on the documents a TENANT reads. Measured on a real CAM
 * credit note: the PDF renders «تسوية» for `reason` (a key, resolved at read time) directly above
 * `CAM reconciliation credit — 2026`, composed with `__()` at write time and stored — so it freezes
 * in whichever language the operator happened to be running. And `MonthlyBillingService` appends a
 * raw-English ` (75% pro-rated)` and ` (in arrears)` to lines on every monthly invoice in the
 * portfolio, which was never translatable at all.
 *
 * The `description` column STAYS and is still written. It is the FLOOR, exactly as the prose columns
 * are on `journal_entries`: every line raised before this has prose and no key, an operator may type
 * their own text on the invoice form, and a reader nobody converted degrades to today's wording
 * rather than to a blank cell. A money document with an unnamed line is worse than one in the wrong
 * language.
 *
 * Nothing is backfilled. The stored sentence is what that document said when it was issued, and
 * inventing a key for it would be guessing at which template produced a string.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoice_items', 'credit_note_items'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                // Nullable, and null is the normal state for every row that exists today.
                $table->string('description_key', 64)->nullable()->after('description');
                $table->json('description_data')->nullable()->after('description_key');
            });
        }
    }

    public function down(): void
    {
        foreach (['invoice_items', 'credit_note_items'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['description_key', 'description_data']);
            });
        }
    }
};
