<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lease event's `reason` is now the FLOOR, not the only channel.
 *
 * `App\Support\LeaseEventNarrative` stamps a KEY into the payload so the sentence
 * is resolved in the READER's language — the same rule `JournalNarrative` states
 * for the ledger. An event carrying a key stores no prose at all, so the column
 * has to accept null; operator-typed prose still goes here and still wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_events', function (Blueprint $table): void {
            $table->text('reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('lease_events', function (Blueprint $table): void {
            $table->text('reason')->nullable(false)->change();
        });
    }
};
