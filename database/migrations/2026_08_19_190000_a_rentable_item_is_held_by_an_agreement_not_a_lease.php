<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A parking bay is held by an AGREEMENT, not specifically by a lease.
 *
 * ## The Yardi grounding, because this is not a design of ours
 *
 * Voyager keeps rentable items (garages, carports, parking spaces, storage, signage) in their own
 * register, and *(cited, `docs/benchmarks/yardi/09-yardi-space-and-parking.md` §2)* "you can assign
 * Rentable Items and Service Charges to both new and existing **residents**". The holder is the
 * CUSTOMER RECORD — the party with a ledger — not the lease document. Billing is then an ordinary
 * recurring charge on that record's schedule, on its own charge code.
 *
 * In Voyager Condo/Co-Op & HOA the unit OWNER simply *is* that customer record, and his dues post
 * to his ledger (module 37 §7). So an owner holding a bay is not an extension of the standard, it
 * is the standard read correctly: Atriom had narrowed "customer record" to "lease" because, when
 * rentable items were built, a lease was the only agreement that existed.
 *
 * `BillableAgreement` is this codebase's name for exactly that idea — the thing that can owe money
 * for occupying a unit — so the pivot keys on it.
 *
 * ## Why a rename rather than a nullable second FK
 *
 * `lease_rentable_item` would become a lie the moment an ownership could hold a row, and two
 * nullable foreign keys with "exactly one must be set" is a constraint the database cannot state
 * and the application forgets. The morph is the shape the rest of this codebase already uses for
 * "one of several agreement types" (`charges`, `invoices`, `credit_notes` all carry the ownership
 * beside the lease), and there is a morph map, so the stored value is a stable alias rather than a
 * class name.
 *
 * ## The backfill
 *
 * Every existing row is a lease holding, so `holder_type` takes the `lease` alias and `holder_id`
 * takes `lease_id`. Written with the literal alias rather than `Lease::class` because a migration
 * must keep meaning what it meant on the day it ran, even if the class moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Column surgery FIRST, rename SECOND. Laravel derives constraint names from the CURRENT
        // table name, so `dropForeign(['lease_id'])` after a rename looks for
        // `rentable_item_holdings_lease_id_foreign` — a constraint that does not exist, because
        // MySQL kept the original `lease_rentable_item_lease_id_foreign`. Renaming last keeps every
        // generated name matching what is actually in the schema.
        Schema::table('lease_rentable_item', function (Blueprint $table) {
            $table->string('holder_type', 64)->nullable()->after('id');
            $table->unsignedBigInteger('holder_id')->nullable()->after('holder_type');
        });

        // Every row that exists today is a lease holding — rentable items predate any other
        // agreement being able to hold one. The literal alias, not `Lease::class`: a migration must
        // keep meaning what it meant on the day it ran, even if the class later moves.
        DB::table('lease_rentable_item')->update([
            'holder_type' => 'lease',
            'holder_id' => DB::raw('lease_id'),
        ]);

        // Order matters twice over. The FOREIGN KEY goes first: MySQL refuses to drop
        // `lease_item_from_unique` while a constraint depends on it for its index
        // ("Cannot drop index … needed in a foreign key constraint"), so unique-then-FK fails on
        // the real engine while passing on sqlite, which has no such rule. Then the index, then
        // the column.
        Schema::table('lease_rentable_item', function (Blueprint $table) {
            $table->dropForeign(['lease_id']);
        });

        Schema::table('lease_rentable_item', function (Blueprint $table) {
            $table->dropUnique('lease_item_from_unique');
            $table->dropColumn('lease_id');
        });

        Schema::table('lease_rentable_item', function (Blueprint $table) {
            $table->string('holder_type', 64)->nullable(false)->change();
            $table->unsignedBigInteger('holder_id')->nullable(false)->change();
        });

        Schema::rename('lease_rentable_item', 'rentable_item_holdings');

        Schema::table('rentable_item_holdings', function (Blueprint $table) {
            // The old uniqueness rule restated over the holder: an item can be re-let after
            // release, so the key includes the start date rather than being one row per
            // (holder, item) for ever.
            $table->unique(
                ['holder_type', 'holder_id', 'rentable_item_id', 'effective_from'],
                'holding_from_unique',
            );
            $table->index(['holder_type', 'holder_id'], 'holding_holder_index');
        });
    }

    public function down(): void
    {
        Schema::rename('rentable_item_holdings', 'lease_rentable_item');

        Schema::table('lease_rentable_item', function (Blueprint $table) {
            $table->dropUnique('holding_from_unique');
            $table->dropIndex('holding_holder_index');
            $table->foreignId('lease_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // Only lease holdings can survive the reversal — an ownership's bay has no column to go
        // back to. Deleted rather than silently orphaned, and stated here so the loss is a decision
        // on the record instead of a surprise.
        DB::table('lease_rentable_item')
            ->where('holder_type', 'lease')
            ->update(['lease_id' => DB::raw('holder_id')]);

        DB::table('lease_rentable_item')->whereNull('lease_id')->delete();

        Schema::table('lease_rentable_item', function (Blueprint $table) {
            $table->dropColumn(['holder_type', 'holder_id']);
            $table->unique(['lease_id', 'rentable_item_id', 'effective_from'], 'lease_item_from_unique');
        });
    }
};
