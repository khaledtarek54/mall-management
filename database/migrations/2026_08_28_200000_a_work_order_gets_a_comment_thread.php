<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **A work order has no comment thread, and a tenant request does.**
 *
 * `TenantRequest` has carried `TenantRequestComment` with an `is_internal` flag since module 11; the
 * work order has `notes` — one field, last-writer-wins, with no author and no timestamp. So the
 * conversation a job actually generates ("access arranged for Sunday", "part is on back-order",
 * "the tenant refused entry") either overwrites itself or lives in somebody's WhatsApp.
 *
 * **This is step 1 of the vendor-portal build order** (`docs/modules/12b-VENDOR-PORTAL-DESIGN.md`
 * §7–§8) and the only new domain object that design needs — every other verb is a screen over
 * something already built. It is deliberately first because it is **useful on its own even if the
 * portal never ships**: an operator commenting on a job today has nowhere to put it.
 *
 * **`is_internal` is the load-bearing column**, exactly as on the tenant thread. Without it there is
 * no way for the operator to write something the contractor must not read, and the portal's whole
 * premise — a contractor sees only what is theirs — would be a promise the schema cannot keep.
 *
 * **The author is a MORPH**, because three different kinds of party write here: a `User` (staff), a
 * `VendorContact` (once the portal ships) and, for a job raised from a tenant request, potentially
 * the tenant. Polymorphic columns store a morph ALIAS — never compare one against `::class`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_work_order_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_work_order_id')->constrained()->cascadeOnDelete();

            // Morph halves are identified by their PAIR, never by a `_type` suffix rule —
            // App\Support\ActivityLogging::NEVER records why.
            $table->string('author_type', 64);
            $table->unsignedBigInteger('author_id');

            $table->text('body');

            // Default FALSE: a comment is a conversation until someone says otherwise. Defaulting
            // to internal would make the portal silent by accident, which is the failure that is
            // hardest to notice — nothing errors, the contractor simply never hears anything.
            $table->boolean('is_internal')->default(false);

            $table->timestamps();

            // The thread is always read in one direction: this job's comments, oldest first.
            //
            // **Named explicitly, because the derived name is 67 characters and MySQL's identifier
            // limit is 64.** `facility_work_order_comments` + `facility_work_order_id` + `created_at`
            // + `_index` overflows it, and the failure is a hard 1059 on the ALTER — after the CREATE
            // has already succeeded, so the table lands and the migration does not record, leaving a
            // half-applied state that then fails on the retry with "table already exists". SQLite
            // has no such limit, so the suite would never have seen it: the same
            // green-here-fatal-there class CLAUDE.md records for enums and `select tbl.*, x, *`.
            $table->index(['facility_work_order_id', 'created_at'], 'fwoc_order_created_index');
            $table->index(['author_type', 'author_id'], 'fwoc_author_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_work_order_comments');
    }
};
