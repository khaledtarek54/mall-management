<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of OUR banks was this cheque lodged with, and when?
 *
 * `post_dated_cheques` has carried `bank_name` since the register shipped, and it is the **DRAWER's**
 * bank — the tenant's, printed on the face of the cheque. Nothing recorded the other side: which of
 * the operator's own accounts the cheque was presented to for collection. `deposited` has been one
 * of the five statuses all along, so the register could say a cheque was *at a bank* and never which.
 *
 * ## Yardi captures the bank at LODGEMENT, and that is the part worth copying
 *
 * Voyager treats a PDC as a two-stage instrument: received against the lease, then **deposited to a
 * specific bank account** for collection, and clearing merely confirms what that deposit started.
 * The bank belongs to the physical act — you hand one piece of paper to one branch — and it is known
 * on the day. Atriom instead inferred it months later, at CLEARING, from whichever property the
 * operator happened to be looking at: `PostDatedChequeService::clear()` builds its `Payment` without
 * a bank account, so since 2026-09-02 the property default filled it in. Right most of the time and
 * a guess every time.
 *
 * It matters because the reconciliation reads it. `MatchBankStatementLineService::candidatesFor()`
 * finds candidates by the chart account behind the bank — so a cheque banked at NBE and cleared while
 * CIB was on screen becomes a CIB candidate, and the operator matches money against a statement it
 * never appeared on.
 *
 * ## `deposited_on` earns its own column
 *
 * "How long has this cheque been at the bank without clearing?" is the operational question a
 * lodgement register exists to answer, and it cannot be derived: `updated_at` moves for any edit and
 * the cleared date lives on the Payment. A cheque presented three weeks ago and still not cleared is
 * the one to telephone the bank about.
 *
 * ## What this deliberately does NOT do
 *
 * It does not post anything. Module 33's v1 scope is a **recorded operator decision** —
 * *register-only, settle-on-clear* — with the Notes-Receivable-on-lodging accrual
 * (`Dr 11205001 / Cr AR`) documented as a future refinement *deferred pending the accountant*. Both
 * columns are descriptive of an act the operator performed; neither moves a balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_dated_cheques', function (Blueprint $table) {
            // Nullable, and null is the normal state for a cheque still in the drawer — a HELD
            // cheque has not been to a bank, so an unset account is the honest reading rather than a
            // gap. `nullOnDelete` for the reason EG-12 gave: a bank account is `#[DeletionAllowed]`
            // configuration and losing it must never take a money record with it.
            $table->foreignId('bank_account_id')
                ->nullable()
                ->after('bank_name')
                ->constrained('bank_accounts')
                ->nullOnDelete();

            $table->date('deposited_on')->nullable()->after('received_date');

            // "Which cheques are sitting at CIB uncleared, oldest first" — the register's own
            // question, and the one the maturity scan will grow into.
            $table->index(['bank_account_id', 'status'], 'pdc_bank_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('post_dated_cheques', function (Blueprint $table) {
            $table->dropIndex('pdc_bank_status_index');
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropColumn('deposited_on');
        });
    }
};
