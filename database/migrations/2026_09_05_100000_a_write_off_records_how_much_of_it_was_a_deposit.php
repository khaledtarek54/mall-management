<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a write-off reached a SECURITY-DEPOSIT line, frozen on the row (SW-210).
 *
 * A write-off must reverse whatever the invoice line originally CREDITED: revenue for a revenue
 * line, and `deposits_held` — a LIABILITY — for a `security_deposit` line, against which no revenue
 * was ever recognised. Splitting the entry that way needs a figure, and that figure MUST be frozen
 * rather than re-derived.
 *
 * **Why frozen.** The split is a function of what is still outstanding per line, and a partial
 * write-off leaves the invoice LIVE (`settled_short` is a shipped reason — "forgive part, the tenant
 * pays the rest" is the canonical case). So a payment arriving a month later moves the split, and
 * `LedgerPoster::matches()` compares the line signature — the already-posted entry would be voided
 * and re-posted at its ORIGINAL date. If that month has since closed the re-post throws, the sync
 * counts a failure, and `gl_in_sync` reports drift for ever with no operator action available: SW-236
 * exactly, arrived at through a different door. Reversing one write-off would likewise restate a
 * SIBLING's entry. A write-off's entry could not drift before this and must not start.
 *
 * **The backfill is 0.00, deliberately — the change is PROSPECTIVE.** Yardi's rule for a
 * classification change is that past entries keep the accounts they were posted to and only new
 * documents use the new one, and it is the rule this system reaches for whenever origination and
 * history disagree (an issued invoice keeps the rate it was billed at). Backfilling the TRUE split
 * would be the alternative, and it would re-post every historical write-off that touched a deposit
 * line — turning a currently-green `gl_in_sync` red on exactly the installs SW-210 is about, and
 * blocking `deploy.sh` behind a closed period nobody can reopen while the year's closing entry
 * stands. So every existing row keeps the entry it already has; `deposits_tie_out` stays as red as
 * it is today for those, which is the state they are already in, and is correctable per invoice by
 * reversing and re-taking the write-off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_write_offs', function (Blueprint $table) {
            $table->decimal('deposit_amount', 14, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_write_offs', function (Blueprint $table) {
            $table->dropColumn('deposit_amount');
        });
    }
};
