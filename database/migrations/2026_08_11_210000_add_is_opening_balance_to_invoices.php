<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mark an invoice as a migrated OPENING ITEM — a debt that was already owed on the day Atriom
 * took over, carried in from the operator's previous system.
 *
 * **Why the flag has to exist.** At cutover the operator's open receivables must arrive as real
 * invoices, not as one lump-sum balance per tenant: aging buckets, dunning, statements and
 * per-invoice payment allocation all work on documents, and a single balance has no number, no due
 * date and nothing to allocate against. That is what Yardi and MRI both load, and for the same
 * reason.
 *
 * But a real invoice posts `Dr AR / Cr Revenue`, and that revenue was **earned before Atriom
 * existed** — it belongs to the previous system's books, and it is already inside the opening
 * trial balance the accountant loads as a manual journal entry. Posting it again would recognise
 * the same revenue twice and inflate AR to double the debt.
 *
 * So an opening item is deliberately a sub-ledger-only document: `InvoiceJournalizer` returns no
 * payload for it, exactly as it already does for a draft. The GL side comes from the opening
 * journal entry, once, in the accountant's own hand.
 *
 * **This makes the tie-out the migration's proof.** `glTieOut()` counts these invoices in
 * `expectedAr` and compares against GL AR — which the opening entry populates — so
 * `billing:reconcile` going green after the import is the statement "the receivables I loaded
 * equal the receivables my accountant says I have". A migration that silently loaded 90% of the
 * debt is otherwise indistinguishable from one that worked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('is_opening_balance')
                ->default(false)
                ->after('status')
                ->comment('Migrated open item from the previous system: sub-ledger only, no GL posting');

            // The reconciliation and the "what did we import?" review both filter on it, and both
            // run over the whole invoice table.
            $table->index('is_opening_balance');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['is_opening_balance']);
            $table->dropColumn('is_opening_balance');
        });
    }
};
