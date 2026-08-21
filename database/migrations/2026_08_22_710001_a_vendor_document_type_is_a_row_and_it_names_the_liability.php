<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The supplier compliance file — six types in PHP constants, and one of them decides who may be
 * sent onto the mall floor.
 *
 * `VendorDocument::BLOCKING_TYPES` is a LIABILITY DECISION held in an array literal: a lapsed
 * insurance certificate stops a vendor being dispatched, a lapsed tax card does not. That is the
 * right default and it is not the operator's to change without a deploy — and it is precisely the
 * kind of ruling that moves. An Egyptian mall dealing with a government client may be told that a
 * lapsed social-insurance certificate (شهادة تأمينات اجتماعية) also stops site work, because the
 * principal carries the contractor's unpaid contributions. Today that is a code change.
 *
 * The six types are also not the whole world. A fire-safety contractor needs a civil-defence permit;
 * a lift company needs an equipment certificate; a food-court cleaner needs health cards. All of
 * them arrive today as "Other", which is the bucket where a compliance chase goes to be forgotten:
 * an expiring "Other" tells the operator nothing about what is expiring.
 *
 * `vendor_documents.type` also had NO `ValueSets` entry, so the column accepted anything.
 *
 * ## What is deliberately NOT here
 *
 * A per-type alert window. `VendorDocument::ALERT_DAYS = 30` is one number shared with the chase
 * scope, the nightly command and the Action Required card, and a per-type value would have to be
 * resolved inside `scopeNeedsAttention()`'s date comparison — a CASE over the catalogue in SQL. A
 * commercial-register renewal does deserve a longer runway than a COI, but that is a change to how
 * the chase is QUERIED, not to what a type is, and folding it in here would hide it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_document_types', function (Blueprint $table) {
            $table->id();

            // The value the document rows already store, so no data migration is needed.
            $table->string('code', 40)->unique();

            $table->string('name_en', 96);
            $table->string('name_ar', 96);

            // THE liability decision. Defaults false — a new type an operator invents does not
            // silently start blocking dispatch; they tick it deliberately.
            $table->boolean('blocks_dispatch')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'vendor_doc_type_active_sort_index');
            $table->index('blocks_dispatch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_document_types');
    }
};
