<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post a correctly-dated document into a different month (story MF-05).
 *
 * **The problem.** A February vendor bill that arrives after February closes cannot post: its GL
 * date is derived from the document's own date, and that period is sealed. Today the operator's only
 * options are to re-date the document — falsifying what the vendor actually invoiced, and what the
 * tenant or the ETA payload will show — or to leave it unposted. Yardi separates the two: every
 * transaction carries a **document date** and a **post month**, and reports run on the post month
 * (02-yardi-money-flow.md).
 *
 * **One table, not twenty-four columns.** Atriom has 24 GL posting sources. A `post_month` column on
 * each would be 24 migrations, 24 form fields and 24 chances to forget one — and a half-implemented
 * post month is worse than none, because an operator cannot tell which documents obey it. A single
 * polymorphic override, consulted where `LedgerPoster` builds every payload, gives all 24 the
 * capability at once and cannot drift between them.
 *
 * The document keeps its own date throughout. Only the JOURNAL ENTRY moves, which is exactly the
 * separation the story asks for: the tenant and the ETA still see February; the books see March.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_month_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            // The first of the target month. Stored as a date so period lookups work unchanged.
            $table->date('post_month');
            // Required by the service: moving a document out of its own period is a decision, and
            // the auditor's first question is why.
            $table->string('reason');
            $table->foreignId('set_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One override per document — re-stating it replaces, never stacks.
            $table->unique(['source_type', 'source_id'], 'posting_month_override_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_month_overrides');
    }
};
