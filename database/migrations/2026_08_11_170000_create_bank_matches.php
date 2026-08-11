<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link between what the bank says and what the books say — slice 3, where the control lands.
 *
 * A match is an ANNOTATION. It posts nothing, moves no balance, and creating or removing one leaves
 * every account exactly where it was. That is the property that keeps the reconciliation screen from
 * becoming a back door into the GL: if a bank charge has no book entry, the answer is to record an
 * expense through the normal path — with its posting-date and approval guards — and then match it.
 *
 * **Cardinality, deliberately asymmetric.** A statement line may carry SEVERAL matches, because a
 * bank legitimately shows one line for two cheques banked together. A journal line may be matched
 * at most ONCE — enforced by a unique index, not by a check — because matching one book posting
 * twice would report the same money as verified twice and is the failure this whole module exists
 * to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_matches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bank_statement_line_id')->constrained()->cascadeOnDelete();

            // The BOOK side is a journal line, not a document. Deriving candidates from the ledger
            // means every money source is included automatically — a hand-written list of the ten
            // models that touch cash goes stale the day an eleventh ships, and it fails silently.
            $table->foreignId('journal_line_id')->constrained()->cascadeOnDelete();

            $table->foreignId('matched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at');
            $table->text('notes')->nullable();

            $table->timestamps();

            // One book posting can be explained by only one statement line.
            $table->unique('journal_line_id');
            $table->index('bank_statement_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_matches');
    }
};
