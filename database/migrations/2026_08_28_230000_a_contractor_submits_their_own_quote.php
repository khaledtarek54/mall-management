<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Who submitted this quote — the contractor, or an operator on their behalf?**
 *
 * `work_order_proposals.submitted_by_user_id` has always meant *the operator who keyed it*, and the
 * model's own docblock says so in as many words, anticipating this portal: *"ServiceChannel's
 * provider submits their own. That portal is gap O2 and is not built, so this is entered on the
 * contractor's behalf exactly as a vendor bill is."*
 *
 * Step 5 of `docs/modules/12b-VENDOR-PORTAL-DESIGN.md` makes the other case real. **A second nullable
 * column rather than a morph**, deliberately: the two answer DIFFERENT questions — "which of our
 * staff typed this in" and "which of their people sent it" — so they are not two truths about one
 * fact. Exactly one is set; both null is a legacy row from before either was recorded.
 *
 * A morph would have been right if the author were one question with several possible types (which
 * is why `facility_work_order_comments.author_type` IS a morph — three kinds of party write there).
 * Here the distinction is the point: an operator seeing a quote wants to know whether it arrived or
 * was transcribed, because a transcribed quote carries whatever the phone call carried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_proposals', function (Blueprint $table) {
            $table->foreignId('submitted_by_vendor_contact_id')
                ->nullable()
                ->after('submitted_by_user_id')
                // NULL on delete, not cascade: removing a contact must never delete the quote they
                // sent. The commercial record outlives the person who typed it.
                ->constrained('vendor_contacts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_order_proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by_vendor_contact_id');
        });
    }
};
