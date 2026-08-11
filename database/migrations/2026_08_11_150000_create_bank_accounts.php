<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's actual bank accounts — slice 1 of bank reconciliation
 * (docs/accounting/BANK-RECONCILIATION-PLAN.md).
 *
 * `bank` and `cash` are account ROLES today, resolved one-per-property through
 * `account_mappings`. That is enough to POST to a bank and not enough to RECONCILE one: a
 * reconciliation is always of a single named account, and with two banks in one property the role
 * is ambiguous. Reconciling "the bank role" would balance and be wrong.
 *
 * Purely additive on its own — nothing reads this table yet, and the posting path is untouched, so
 * day one behaviour is identical. What it buys immediately is that the operator's banks exist
 * somewhere other than a posting-map row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();

            // Property-owned: an account belongs to the mall whose money it holds. Classified
            // ISOLATED in App\Support\PropertyIsolation.
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();

            $table->string('name');                          // "CIB — current"
            $table->string('bank_name')->nullable();
            // Masked in the UI; stored whole because a reconciliation is matched against statements
            // that quote it, and a truncated number cannot be matched back.
            $table->string('account_number')->nullable();
            $table->string('iban')->nullable();
            $table->string('currency', 3)->default('EGP');

            // The GL account this bank IS. Nullable so an operator can register the account before
            // the accountant has decided where it posts, and restricted on delete because an
            // account that has been posted to must not lose the link that explains its balance.
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            // Fold-normalised search blob (App\Models\Concerns\HasSearchText). Its own column
            // because the shared 2026-07-31 migration is a fixed snapshot — a model added to
            // SearchPolicy::INDEXED afterwards brings its own.
            $table->text('search_text')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One account number per property — the same shape as every other per-property code.
            $table->unique(['asset_id', 'account_number']);
            $table->index(['asset_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
