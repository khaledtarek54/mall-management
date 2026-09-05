<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `reason_notes_key` + `reason_notes_data` on `credit_notes` — the half UX-30 left.
 *
 * The LINES were converted on 2026-09-05/06. The note above them was not, and it is the sentence a
 * tenant reads first: `CamReconciliationService` wrote a raw-English *"CAM reconciliation credit —
 * 2026"*, and `CreditUnearnedBillingService` resolved `__()` at write time with dates formatted
 * `d/m/Y`, so the whole explanation froze in whichever language the move-out was settled in. On the
 * demo books that put an English paragraph directly above Arabic line text on one document.
 *
 * Same contract as the lines: `reason_notes` stays as the FLOOR and as an operator's own words —
 * it is a `Textarea` on the credit-note form, and typing there clears the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->string('reason_notes_key', 64)->nullable()->after('reason_notes');
            $table->json('reason_notes_data')->nullable()->after('reason_notes_key');
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn(['reason_notes_key', 'reason_notes_data']);
        });
    }
};
