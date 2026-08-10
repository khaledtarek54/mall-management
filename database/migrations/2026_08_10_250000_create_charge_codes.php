<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أكواد الرسوم — the charge-code catalogue (gap-analysis row 216).
 *
 * Yardi treats a charge code as configuration an accountant maintains: the code, what it is called,
 * and which GL account it posts to. Atriom had the code as a **PHP enum** and the code → account
 * link as a **private const map** inside `InvoiceJournalizer`, so billing "key money" or a "chiller
 * charge" — both ordinary Egyptian mall line items — meant a developer and a deploy.
 *
 * **What this table owns, and what it deliberately does not.** It owns the catalogue: which codes
 * exist, what they are called in both languages, and the posting role each one books to. It does
 * NOT own behaviour. A handful of codes carry real logic — `cam_recovery` and `percentage_rent` are
 * excluded from the monthly anti-double-bill probe, `late_fee` and `nsf_fee` settle last in
 * `InvoiceItemSettlement` — and that logic stays in code, keyed on the `InvoiceItemType` constants.
 * The enum therefore survives as *named references to the codes that carry logic*, not as the list
 * of what may be billed. `ChargeCodeGlMappingConformanceTest` asserts every enum case exists in
 * this table, so the two cannot drift.
 *
 * That split is the whole design. Making the catalogue data lets an accountant add a code; keeping
 * behaviour in code stops them accidentally creating one the billing engine has opinions about.
 *
 * `posting_role` is a key from `App\Support\PostingRoles`, resolved to a real account through
 * `account_mappings` — so a code inherits the per-property override the posting map already
 * supports, rather than needing its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_codes', function (Blueprint $table) {
            $table->id();
            // The value stored in `invoice_items.type`. Short, snake_case, stable — renaming one
            // would orphan every historical line, so the UI edits the LABEL, never this.
            $table->string('code', 32)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            // A key from App\Support\PostingRoles. Nullable = falls back to misc_income, which is
            // what `other` does deliberately.
            $table->string('posting_role', 64)->nullable();
            $table->boolean('is_active')->default(true);
            // Order in the invoice-line picker; the codes an operator bills daily come first.
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_codes');
    }
};
