<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only record of every commercial change to a lease (story LE-01).
 *
 * **Why this exists.** Phase 1 gave the lease a date-ranged charge schedule, so the system can now
 * answer *what* the rent was on any past date and *when* it changed. It still cannot answer **why**.
 * A mid-term rent reduction, a negotiated relief, an expansion and a data-entry correction all look
 * identical in the schedule — four rows with dates — and the only trace of intent is a sentence
 * `LeaseRentChangeService` appends to `leases.notes`, which is prose nobody can query, report on, or
 * tie to a signed amendment.
 *
 * The activity log is not a substitute: it records that a column changed, not the business meaning,
 * not the date the change takes effect (which is rarely the date it was typed), and not the document
 * that authorises it. An auditor asking "show me the amendment behind this rent" needs all three.
 *
 * **Append-only, enforced on the model.** An event is an assertion about something that happened;
 * editing one rewrites history, which is the whole thing this table exists to prevent. A mistaken
 * event is corrected by recording the correcting event — the same discipline the money records
 * already follow (`RefusesDeletionOfCommittedRecords`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();

            // String, not a DB enum — the house rule. The vocabulary lives on the model, so adding
            // a type is a code change and not a migration.
            $table->string('type');

            // The date the change TAKES EFFECT, which is what the schedule and every report key
            // off. Distinct from created_at, the date it was recorded: a relief agreed in March and
            // effective in January is normal, and conflating the two misdates the money.
            $table->date('effective_date');

            $table->text('reason');

            // The signed paper behind the change — an amendment number, a letter reference. Free
            // text on purpose: what counts as a document reference differs per owner, and a lookup
            // table would be a second thing to maintain before anyone has asked for one.
            $table->string('document_reference')->nullable();

            // Nullable: a scheduled sweep (escalation, auto-close) has no human actor, and
            // pretending otherwise would put a false name in the audit trail.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // What actually moved: before/after amounts, the schedule rows opened and closed, the
            // units added or removed. Enough to reconstruct the change without re-deriving it from
            // the schedule, and enough to show a meaningful timeline line without a join per row.
            $table->json('payload')->nullable();

            $table->timestamps();

            // The timeline query: one lease, chronological.
            $table->index(['lease_id', 'effective_date']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_events');
    }
};
